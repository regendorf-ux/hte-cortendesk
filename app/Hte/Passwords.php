<?php

namespace App\Hte;

use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AddressBookRule;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HTE (2026-10-16): each HTE Remote computer gets its own password, made and changed by the console.
 *
 * - The HTE Remote client (patched) sends `hte_pw` = the version it holds with every check-in
 *   (POST /api/heartbeat). The console answers `hte_password` = {v, box} while that differs from the
 *   newest: `box` is the password sealed (libsodium sealed box) to that computer's own key, the
 *   ed25519 public key it registered with the ID server, read from the ID server's peer table. Only
 *   that computer can open it, so a spoofed check-in learns nothing.
 * - When a check-in reports the newest version, the computer has it: it becomes the confirmed
 *   password, and the automatic address books carry it from then on. Until then the old one stays in
 *   the books and keeps working on the computer.
 * - Each person gets one shared book named "HTE Remote", readable only by them, holding exactly the
 *   computers they may reach (Device::visibleTo), with the passwords filled in. Kept in step every
 *   minute by `hte:passwords`, which also changes passwords on schedule and at once when someone loses
 *   access to a computer.
 * - Computers on stock RustDesk (or an HTE Remote without the patch) never send `hte_pw`: they are in
 *   the books without a password, as before.
 */
class Passwords
{
    public const SETTING_ENABLED = 'hte_pw_enabled';

    public const SETTING_DAYS = 'hte_pw_days';

    public const DAY_CHOICES = [7 => 'Every 7 days', 30 => 'Every 30 days', 90 => 'Every 90 days', 0 => 'Only when someone loses access'];

    public const BOOK_NAME = 'HTE Remote';

    public const BOOK_NOTE = 'Automatic (HTE): the computers this person may reach, kept in step by the console. Not editable.';

    /** Tests point this at a stand-in peer table. */
    public static ?string $hbbsDbPath = null;

    /** Where the ID server keeps its peers (the console image runs hbbs beside it). */
    public static function hbbsDb(): string
    {
        return self::$hbbsDbPath ?? '/data/rustdesk/db_v2.sqlite3';
    }

    public static function enabled(): bool
    {
        return Setting::get(self::SETTING_ENABLED, '0') === '1';
    }

    public static function days(): int
    {
        $d = (int) Setting::get(self::SETTING_DAYS, '30');

        return array_key_exists($d, self::DAY_CHOICES) ? $d : 30;
    }

    // ---- the check-in ---------------------------------------------------------------------

    /**
     * Hooked into SyncController::heartbeat after the device is known and its uuid matched.
     *
     * @param  array<string,mixed>  $response
     * @return array<string,mixed>
     */
    public static function heartbeat(Device $device, Request $request, array $response): array
    {
        if (! $request->has('hte_pw')) {
            return $response; // not an HTE Remote that takes passwords
        }
        $held = (int) $request->input('hte_pw', 0);
        $row = DevicePassword::firstOrNew(['rustdesk_id' => (string) $device->rustdesk_id]);
        $row->capable_at = now();

        if ($row->current_version > 0 && $held === $row->current_version && $row->confirmed_version !== $held) {
            $row->confirmed_version = $held;
            $row->confirmed_enc = $row->current_enc;
            $row->confirmed_at = now();
            $row->save();
            self::updateBookEntries($row);
        }
        $row->save();

        if (! self::enabled() || $device->status !== Device::STATUS_ACTIVE) {
            return $response;
        }
        if ($row->current_version === 0) {
            $row = self::issue($device, 'first check-in');
        }
        if ($held !== $row->current_version) {
            $box = self::sealFor($device, $row);
            if ($box !== null) {
                $response['hte_password'] = ['v' => $row->current_version, 'box' => $box];
            }
        }

        return $response;
    }

    /** A new password for this computer; sent from its next check-in until it confirms. */
    public static function issue(Device $device, string $reason): DevicePassword
    {
        $row = DevicePassword::firstOrNew(['rustdesk_id' => (string) $device->rustdesk_id]);
        $row->current_version = max($row->current_version, $row->confirmed_version) + 1;
        $row->current_enc = Crypt::encryptString(self::generate());
        $row->issued_at = now();
        $row->issued_reason = Str::limit($reason, 60, '');
        $row->save();

        return $row;
    }

    /** 20 characters from an alphabet without look-alikes; ~117 bits. */
    public static function generate(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 20; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /** The computer's ed25519 public key as the ID server registered it, or null. */
    public static function devicePublicKey(string $rustdeskId): ?string
    {
        $path = self::hbbsDb();
        if (! is_readable($path)) {
            return null;
        }
        try {
            $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
            $stmt = $pdo->prepare('select pk from peer where id = ? limit 1');
            $stmt->execute([$rustdeskId]);
            $pk = $stmt->fetchColumn();
        } catch (\Throwable) {
            return null;
        }

        return is_string($pk) && strlen($pk) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $pk : null;
    }

    /** base64 of the password sealed to the computer's key, or null when its key is unknown. */
    public static function sealFor(Device $device, DevicePassword $row): ?string
    {
        $pk = self::devicePublicKey((string) $device->rustdesk_id);
        if ($pk === null || $row->current_enc === null) {
            return null;
        }
        $payload = json_encode(['v' => $row->current_version, 'pw' => Crypt::decryptString($row->current_enc)]);

        return base64_encode(sodium_crypto_box_seal($payload, sodium_crypto_sign_ed25519_pk_to_curve25519($pk)));
    }

    // ---- the minute sweep -------------------------------------------------------------------

    /** Changes passwords that are due or whose computer lost a person, then refreshes the books. */
    public static function sweep(): array
    {
        $stats = ['issued' => 0, 'books' => 0];
        if (! self::enabled()) {
            return $stats;
        }
        $days = self::days();

        DevicePassword::query()->whereNotNull('capable_at')->orderBy('id')->each(function (DevicePassword $row) use ($days, &$stats) {
            $device = Device::query()->where('rustdesk_id', $row->rustdesk_id)->first();
            if ($device === null || $device->status !== Device::STATUS_ACTIVE) {
                return;
            }
            $now = self::reachIds($device);
            $before = $row->reach_user_ids;
            $lost = is_array($before) ? array_values(array_diff($before, $now)) : [];
            $row->reach_user_ids = $now;
            $row->save();

            $reason = null;
            if ($row->current_version === 0) {
                $reason = 'first password';
            } elseif ($lost !== []) {
                $reason = 'access removed ('.User::query()->whereIn('id', $lost)->pluck('username')->implode(', ').')';
            } elseif ($days > 0 && ! $row->pending() && $row->confirmed_at !== null && $row->confirmed_at->lt(now()->subDays($days))) {
                $reason = "every {$days} days";
            }
            if ($reason !== null && ! $row->pending()) {
                self::issue($device, $reason);
                ConsoleAudit::system('hte.password', 'New password for '.$device->rustdesk_id.': '.$reason, 'device', (string) $device->rustdesk_id);
                $stats['issued']++;
            }
        });

        $stats['books'] = self::syncBooks();

        return $stats;
    }

    /** @return list<int> ids of the active people who may reach the device (admins included). */
    public static function reachIds(Device $device): array
    {
        $ids = [];
        foreach (Reach::sections($device) as $section) {
            foreach ($section['users'] as $u) {
                $ids[] = (int) $u->id;
            }
        }
        sort($ids);

        return array_values(array_unique($ids));
    }

    // ---- the automatic address books ------------------------------------------------------------

    public static function bookOwnerId(): int
    {
        return (int) (User::query()->where('is_admin', true)->orderBy('id')->value('id') ?? 0);
    }

    /** One book per active person; removed people's books are emptied. Returns how many were written. */
    public static function syncBooks(): int
    {
        $passwords = DevicePassword::query()->where('confirmed_version', '>', 0)->get()->keyBy('rustdesk_id');
        $written = 0;

        foreach (User::query()->orderBy('id')->get() as $user) {
            $map = DB::table('hte_auto_books')->where('user_id', $user->id)->first();
            $book = $map ? AddressBook::query()->find($map->address_book_id) : null;

            if (! $user->is_active) {
                if ($book) {
                    $book->entries()->delete();
                    $book->rules()->delete();
                }
                continue;
            }
            if ($book === null) {
                $book = AddressBook::create([
                    'guid' => (string) Str::uuid(), 'name' => self::BOOK_NAME,
                    'owner_user_id' => self::bookOwnerId() ?: $user->id, 'is_personal' => false, 'note' => self::BOOK_NOTE,
                ]);
                DB::table('hte_auto_books')->updateOrInsert(['user_id' => $user->id], ['address_book_id' => $book->id, 'updated_at' => now(), 'created_at' => now()]);
            }
            if (! $book->rules()->where('subject_type', 'user')->where('subject_id', $user->id)->exists()) {
                $book->rules()->delete();
                AddressBookRule::create(['address_book_id' => $book->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'permission' => AddressBookRule::PERM_READ]);
            }

            $want = Device::query()->visibleTo($user)->orderBy('rustdesk_id')->get()->keyBy('rustdesk_id');
            $book->entries()->whereNotIn('rustdesk_id', $want->keys()->all() ?: ['-'])->delete();
            foreach ($want as $id => $device) {
                $pw = $passwords->get($id);
                $plain = $pw?->confirmed_enc ? Crypt::decryptString($pw->confirmed_enc) : null;
                $entry = AddressBookEntry::query()->firstOrNew(['address_book_id' => $book->id, 'rustdesk_id' => (string) $id]);
                $current = $entry->password_enc ? Crypt::decryptString($entry->password_enc) : null;
                $fields = ['alias' => (string) ($device->alias ?: $device->hostname), 'hostname' => (string) $device->hostname,
                    'platform' => $device->rustdeskPlatform(), 'username' => (string) $device->username];
                $dirty = ! $entry->exists || $current !== $plain;
                foreach ($fields as $k => $v) {
                    $dirty = $dirty || (string) $entry->{$k} !== $v;
                }
                if ($dirty) {
                    $entry->forceFill($fields + ['password_enc' => $plain !== null ? Crypt::encryptString($plain) : null])->save();
                    $written++;
                }
            }
        }

        return $written;
    }

    /** A computer confirmed a new password: put it in the books at once, not at the next sweep. */
    public static function updateBookEntries(DevicePassword $row): void
    {
        if ($row->confirmed_enc === null) {
            return;
        }
        $bookIds = DB::table('hte_auto_books')->pluck('address_book_id');
        AddressBookEntry::query()->whereIn('address_book_id', $bookIds)->where('rustdesk_id', $row->rustdesk_id)
            ->update(['password_enc' => Crypt::encryptString(Crypt::decryptString($row->confirmed_enc)), 'updated_at' => now()]);
    }

    /** The client's shared-book list: someone else's automatic book is never shown, admins included. */
    public static function hiddenBookFor(AddressBook $book, User $user): bool
    {
        $owner = DB::table('hte_auto_books')->where('address_book_id', $book->id)->value('user_id');

        return $owner !== null && (int) $owner !== (int) $user->id;
    }

    // ---- what the console shows ------------------------------------------------------------------

    /** The device page's "Password" row: [tone, text, sub, canRotate]. */
    public static function rowFor(Device $device): array
    {
        $row = DevicePassword::query()->where('rustdesk_id', (string) $device->rustdesk_id)->first();
        if (! self::enabled()) {
            return ['text-muted', 'Not managed by the console', 'Settings → Server', false];
        }
        if ($row === null || $row->capable_at === null) {
            return ['text-muted', 'Set on the computer itself', 'stock RustDesk or an HTE Remote too old to take one', false];
        }
        if ($row->pending()) {
            return ['text-warning', 'New one sent '.($row->issued_at?->diffForHumans() ?? ''), 'waiting for the computer to confirm; the old one works until then', false];
        }
        $days = self::days();
        $next = $days > 0 && $row->confirmed_at ? 'next change '.$row->confirmed_at->copy()->addDays($days)->diffForHumans() : 'changes when someone loses access';

        return ['', 'Its own, changed '.($row->confirmed_at?->diffForHumans() ?? ''), $next, true];
    }

    /** Settings line: [tone, text]. */
    public static function summary(): array
    {
        if (! self::enabled()) {
            return ['text-muted', 'Off: computers keep the password they were installed with.'];
        }
        $rows = DevicePassword::query()->whereNotNull('capable_at')->get();
        $own = $rows->filter(fn ($r) => $r->confirmed_version > 0 && ! $r->pending())->count();
        $waiting = $rows->filter(fn ($r) => $r->pending())->count();
        $total = Device::query()->approved()->count();
        $other = max(0, $total - $rows->count());
        $text = "Own passwords: {$own} of {$total} computers.";
        if ($waiting > 0) {
            $text .= " {$waiting} waiting for the computer to confirm.";
        }
        if ($other > 0) {
            $text .= " {$other} on stock RustDesk or an older HTE Remote (their password is set on the computer).";
        }

        return [$waiting > 0 ? 'text-warning' : 'text-success', $text];
    }
}
