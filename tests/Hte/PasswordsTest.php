<?php

// HTE (2026-10-16): per-computer passwords. hteFixture() is in ReachTest.php. A stand-in for the ID
// server's peer table holds each computer's real ed25519 public key, as hbbs would.

use App\Hte\DevicePassword;
use App\Hte\Passwords;
use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\ClientToken;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Write an hbbs-shaped peer db with fresh keys; returns [rustdesk id => ed25519 keypair]. */
function hteFakeHbbs(array $ids): array
{
    $path = tempnam(sys_get_temp_dir(), 'hbbs').'.sqlite3';
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('create table peer (guid blob primary key not null, id varchar(100) not null, uuid blob not null, pk blob not null, created_at datetime not null default(current_timestamp), user blob, status tinyint, note varchar(300), info text not null) without rowid');
    $keys = [];
    foreach ($ids as $id) {
        $kp = sodium_crypto_sign_keypair();
        $keys[$id] = $kp;
        $st = $pdo->prepare('insert into peer (guid, id, uuid, pk, info) values (?, ?, ?, ?, ?)');
        $st->execute([random_bytes(16), $id, 'u', sodium_crypto_sign_publickey($kp), '{}']);
    }
    Passwords::$hbbsDbPath = $path;

    return $keys;
}

/** What the patched client does with the box: open it with its own key. */
function hteOpen(string $box, string $kp): array
{
    $curve = sodium_crypto_box_keypair_from_secretkey_and_publickey(
        sodium_crypto_sign_ed25519_sk_to_curve25519(sodium_crypto_sign_secretkey($kp)),
        sodium_crypto_sign_ed25519_pk_to_curve25519(sodium_crypto_sign_publickey($kp)),
    );

    return json_decode(sodium_crypto_box_seal_open(base64_decode($box), $curve), true);
}

function hteBeat($test, string $id, string $uuid, ?int $held)
{
    $body = ['id' => $id, 'uuid' => $uuid];
    if ($held !== null) {
        $body['hte_pw'] = $held;
    }

    return $test->postJson('/api/heartbeat', $body)->assertOk()->json();
}

test('a computer gets its own sealed password at check-in and confirms it', function () {
    $f = hteFixture();
    $keys = hteFakeHbbs(['100000001', '555000111']);
    Setting::put(Passwords::SETTING_ENABLED, '1');

    // An unpatched client (no hte_pw) is left alone.
    expect(hteBeat($this, '100000001', 'uuid-100000001', null))->not->toHaveKey('hte_password');
    expect(DevicePassword::count())->toBe(0);

    $r = hteBeat($this, '100000001', 'uuid-100000001', 0);
    expect($r['hte_password']['v'])->toBe(1);
    $inner = hteOpen($r['hte_password']['box'], $keys['100000001']);
    expect($inner['v'])->toBe(1)->and(strlen($inner['pw']))->toBe(20);

    // Another computer's key cannot open it.
    expect(fn () => hteOpen($r['hte_password']['box'], $keys['555000111']))->toThrow(TypeError::class);

    // Still pending: sent again, same version, same password.
    $again = hteBeat($this, '100000001', 'uuid-100000001', 0);
    expect(hteOpen($again['hte_password']['box'], $keys['100000001'])['pw'])->toBe($inner['pw']);

    // The client reports v1: confirmed, nothing more sent.
    expect(hteBeat($this, '100000001', 'uuid-100000001', 1))->not->toHaveKey('hte_password');
    $row = DevicePassword::where('rustdesk_id', '100000001')->first();
    expect($row->confirmed_version)->toBe(1)->and(Crypt::decryptString($row->confirmed_enc))->toBe($inner['pw']);

    // A spoofed check-in with the wrong uuid gets nothing.
    Passwords::issue($f['front'], 'test');
    expect(hteBeat($this, '100000001', 'not-the-uuid', 1))->not->toHaveKey('hte_password');
});

test('off: computers are recorded as able but get nothing', function () {
    hteFixture();
    hteFakeHbbs(['100000001']);
    expect(hteBeat($this, '100000001', 'uuid-100000001', 0))->not->toHaveKey('hte_password');
    expect(DevicePassword::where('rustdesk_id', '100000001')->first()->capable_at)->not->toBeNull();
});

test('each person gets one HTE Remote book with exactly the computers they may reach', function () {
    $f = hteFixture();
    $keys = hteFakeHbbs(['100000001', '555000111']);
    Setting::put(Passwords::SETTING_ENABLED, '1');
    foreach (['100000001', '555000111'] as $id) {
        hteBeat($this, $id, 'uuid-'.$id, 0);
        hteBeat($this, $id, 'uuid-'.$id, 1);
    }
    Passwords::sweep();

    $bookOf = fn ($user) => AddressBook::find(DB::table('hte_auto_books')->where('user_id', $user->id)->value('address_book_id'));
    $ids = fn ($user) => $bookOf($user)->entries()->orderBy('rustdesk_id')->pluck('rustdesk_id')->all();
    expect($ids($f['maria']))->toBe(['100000001'])
        ->and($ids($f['juan']))->toBe(['100000001', '555000111'])
        ->and($ids($f['luis']))->toBe([])
        ->and($ids($f['osvaldo']))->toBe(['100000001', '100000002', '555000111']);

    $front = AddressBookEntry::where('address_book_id', $bookOf($f['maria'])->id)->first();
    $confirmed = Crypt::decryptString(DevicePassword::where('rustdesk_id', '100000001')->first()->confirmed_enc);
    expect(Crypt::decryptString($front->password_enc))->toBe($confirmed);
    // The Mac never checked in as able: listed, no password.
    expect(AddressBookEntry::where('address_book_id', $bookOf($f['osvaldo'])->id)->where('rustdesk_id', '100000002')->first()->password_enc)->toBeNull();

    // The client API: María sees one HTE Remote book (hers), with the password; Osvaldo, an admin, only his own.
    $this->withToken($f['tokens']['mperez'])->postJson('/api/ab/shared/profiles')->assertOk()
        ->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'HTE Remote')->assertJsonPath('data.0.rule', 1);
    $guid = $bookOf($f['maria'])->guid;
    $this->withToken($f['tokens']['mperez'])->postJson('/api/ab/peers?ab='.$guid)->assertOk()
        ->assertJsonPath('data.0.id', '100000001')->assertJsonPath('data.0.password', $confirmed);
    app('auth')->forgetGuards();
    $this->withToken($f['tokens']['oduran'])->postJson('/api/ab/shared/profiles')->assertOk()->assertJsonPath('total', 1);
    // Luis cannot read María's book, nor list it.
    app('auth')->forgetGuards();
    $this->withToken($f['tokens']['lgomez'])->postJson('/api/ab/shared/profiles')->assertOk()->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.guid', $bookOf($f['luis'])->guid);
    app('auth')->forgetGuards();
    $this->withToken($f['tokens']['lgomez'])->postJson('/api/ab/peers?ab='.$guid)->assertJson(['error' => 'Permission denied']);
});

test('losing access changes the password; the book follows once the computer confirms', function () {
    $f = hteFixture();
    $keys = hteFakeHbbs(['555000111']);
    Setting::put(Passwords::SETTING_ENABLED, '1');
    hteBeat($this, '555000111', 'uuid-555000111', 0);
    hteBeat($this, '555000111', 'uuid-555000111', 1);
    Passwords::sweep();
    $first = Crypt::decryptString(DevicePassword::where('rustdesk_id', '555000111')->first()->confirmed_enc);

    // Juan leaves Support: he loses the server, and its password changes.
    DB::table('user_group_user')->where('user_id', $f['juan']->id)->delete();
    $s = Passwords::sweep();
    expect($s['issued'])->toBe(1);
    $row = DevicePassword::where('rustdesk_id', '555000111')->first();
    expect($row->current_version)->toBe(2)->and($row->issued_reason)->toContain('jrivera');

    // Until the computer confirms, the books keep the old one (it still works there).
    $bookOsvaldo = DB::table('hte_auto_books')->where('user_id', $f['osvaldo']->id)->value('address_book_id');
    $entry = fn () => Crypt::decryptString(AddressBookEntry::where('address_book_id', $bookOsvaldo)->where('rustdesk_id', '555000111')->first()->password_enc);
    expect($entry())->toBe($first);
    $r = hteBeat($this, '555000111', 'uuid-555000111', 1);
    $second = hteOpen($r['hte_password']['box'], $keys['555000111'])['pw'];
    hteBeat($this, '555000111', 'uuid-555000111', 2);
    expect($entry())->toBe($second)->and($second)->not->toBe($first);

    // Nothing else lost: no new password on the next sweep.
    expect(Passwords::sweep()['issued'])->toBe(0);
});

test('a password older than the schedule is changed', function () {
    hteFixture();
    hteFakeHbbs(['555000111']);
    Setting::put(Passwords::SETTING_ENABLED, '1');
    Setting::put(Passwords::SETTING_DAYS, '7');
    hteBeat($this, '555000111', 'uuid-555000111', 0);
    hteBeat($this, '555000111', 'uuid-555000111', 1);
    Passwords::sweep();
    DevicePassword::where('rustdesk_id', '555000111')->update(['confirmed_at' => now()->subDays(8)]);
    expect(Passwords::sweep()['issued'])->toBe(1);
    Setting::put(Passwords::SETTING_DAYS, '0');
    DevicePassword::where('rustdesk_id', '555000111')->update(['confirmed_version' => 2, 'confirmed_at' => now()->subDays(400)]);
    expect(Passwords::sweep()['issued'])->toBe(0);
});

test('the console shows it: settings, device row, change now', function () {
    $f = hteFixture();
    hteFakeHbbs(['100000001']);
    $this->actingAs($f['osvaldo']);
    $this->get('/settings')->assertOk()->assertSee('Each computer gets its own password, made and changed by the console')
        ->assertSee('Every 30 days (30)');

    \Livewire\Livewire::actingAs($f['osvaldo'])->test(\App\Livewire\SettingsPage::class)
        ->set('htePasswords', true)->set('htePasswordDays', 90)->call('save')->assertHasNoErrors();
    expect(Passwords::enabled())->toBeTrue()->and(Passwords::days())->toBe(90);

    $this->get('/devices/'.$f['front']->id)->assertOk()->assertSee('Set on the computer itself');
    hteBeat($this, '100000001', 'uuid-100000001', 0);
    $this->get('/devices/'.$f['front']->id)->assertSee('waiting for the computer to confirm');
    hteBeat($this, '100000001', 'uuid-100000001', 1);
    $this->get('/devices/'.$f['front']->id)->assertSee('Its own, changed')->assertSee('change now');

    \Livewire\Livewire::actingAs($f['osvaldo'])->test(\App\Livewire\DeviceDetail::class, ['deviceId' => $f['front']->id])
        ->call('hteRotatePassword');
    expect(DevicePassword::where('rustdesk_id', '100000001')->first()->current_version)->toBe(2);
    \Livewire\Livewire::actingAs($f['maria'])->test(\App\Livewire\DeviceDetail::class, ['deviceId' => $f['front']->id])
        ->call('hteRotatePassword')->assertForbidden();
});
