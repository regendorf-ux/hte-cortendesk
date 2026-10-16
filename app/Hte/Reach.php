<?php

namespace App\Hte;

use App\Models\AlarmLog;
use App\Models\ClientToken;
use App\Models\Device;
use App\Models\GroupAccess;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * HTE (2026-10-16): who may reach which device, enforced by the ID server.
 *
 * The rule is the console's own visibility rule (Device::scopeVisibleTo):
 * administrators reach everything; anyone else reaches the devices assigned
 * to them plus the devices in the device groups granted to them, to their
 * user groups, or through a group access entry. This class only answers two
 * questions from it: the `scope` block hbbs pulls with the policy, and the
 * list of people shown on a device's page.
 */
class Reach
{
    public const SETTING = 'hte_scope_enforce';

    /** Alarm type for a session hbbs refused as outside the person's reach. */
    public const ALARM_TYPE = 102;

    public static function enforced(): bool
    {
        return Setting::get(self::SETTING, '0') === '1';
    }

    /**
     * The `scope` block of GET /api/server/policy. Empty when off, so an
     * unpatched hbbs and a patched one with the setting off behave alike.
     *
     * @return array{enforce: bool, users: object, token_users: object}
     */
    public static function policyBlock(): array
    {
        if (! self::enforced()) {
            return ['enforce' => false, 'users' => (object) [], 'token_users' => (object) []];
        }

        $users = [];
        User::query()->where('is_active', true)->orderBy('id')
            ->chunk(500, function ($rows) use (&$users) {
                foreach ($rows as $user) {
                    $users[(string) $user->id] = [
                        'name' => (string) $user->username,
                        'all' => (bool) $user->is_admin,
                        'devices' => $user->is_admin
                            ? []
                            : Device::query()->visibleTo($user)->orderBy('rustdesk_id')->pluck('rustdesk_id')->values()->all(),
                    ];
                }
            });

        // A disabled person's tokens map to nobody: hbbs refuses them.
        $tokenUsers = [];
        ClientToken::query()
            ->whereNotNull('user_id')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('id')
            ->select(['id', 'token', 'user_id'])
            ->chunk(2000, function ($rows) use (&$tokenUsers, $users) {
                foreach ($rows as $row) {
                    if (isset($users[(string) $row->user_id])) {
                        $tokenUsers[hash('sha256', $row->token)] = (string) $row->user_id;
                    }
                }
            });
        ksort($tokenUsers);

        return ['enforce' => true, 'users' => (object) $users, 'token_users' => (object) $tokenUsers];
    }

    /**
     * Who may reach a device and how, for its page: sections in the order
     * administrators, owner, then one per way into its group. A person is
     * listed once, under the first section that lets them in.
     *
     * @return list<array{label: string, sub: ?string, users: list<User>}>
     */
    public static function sections(Device $device): array
    {
        $seen = [];
        $sections = [];
        $add = function (string $label, ?string $sub, iterable $users) use (&$seen, &$sections) {
            $fresh = [];
            foreach ($users as $user) {
                if (! $user->is_active || isset($seen[$user->id])) {
                    continue;
                }
                $seen[$user->id] = true;
                $fresh[] = $user;
            }
            if ($fresh !== []) {
                usort($fresh, fn (User $a, User $b) => strcasecmp($a->displayName(), $b->displayName()));
                $sections[] = ['label' => $label, 'sub' => $sub, 'users' => $fresh];
            }
        };

        $add('Administrators', null, User::query()->where('is_admin', true)->orderBy('username')->get());
        if ($device->user) {
            $add('Owner', null, [$device->user]);
        }

        $group = $device->group;
        if ($group === null) {
            return $sections;
        }
        $name = 'Group '.$group->name;

        $add($name, 'granted directly', User::query()
            ->whereIn('id', DB::table('device_group_user')->where('device_group_id', $group->id)->pluck('user_id'))
            ->get());

        $viaUserGroups = DB::table('device_group_user_group')
            ->join('user_groups', 'user_groups.id', '=', 'device_group_user_group.user_group_id')
            ->where('device_group_user_group.device_group_id', $group->id)
            ->orderBy('user_groups.name')
            ->get(['user_groups.id', 'user_groups.name']);
        foreach ($viaUserGroups as $ug) {
            $add($name, 'via user group '.$ug->name, User::query()
                ->whereIn('id', DB::table('user_group_user')->where('user_group_id', $ug->id)->pluck('user_id'))
                ->get());
        }

        $accesses = GroupAccess::query()
            ->where('target_type', GroupAccess::TARGET_DEVICE_GROUP)
            ->where('target_id', $group->id)
            ->get();
        $directIds = $accesses->where('accessor_type', GroupAccess::ACCESSOR_USER)->pluck('accessor_id');
        if ($directIds->isNotEmpty()) {
            $add($name, 'group access', User::query()->whereIn('id', $directIds)->get());
        }
        $accessGroups = DB::table('user_groups')
            ->whereIn('id', $accesses->where('accessor_type', GroupAccess::ACCESSOR_USER_GROUP)->pluck('accessor_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
        foreach ($accessGroups as $ug) {
            $add($name, 'group access for user group '.$ug->name, User::query()
                ->whereIn('id', DB::table('user_group_user')->where('user_group_id', $ug->id)->pluck('user_id'))
                ->get());
        }

        return $sections;
    }

    /** Console usernames who may reach the device, flat. @return list<string> */
    public static function usernames(Device $device): array
    {
        $names = [];
        foreach (self::sections($device) as $section) {
            foreach ($section['users'] as $user) {
                $names[] = $user->username;
            }
        }

        return $names;
    }

    /** The newest refusal hbbs reported for this device as the target. */
    public static function lastRefusal(Device $device): ?AlarmLog
    {
        return AlarmLog::query()
            ->where('typ', self::ALARM_TYPE)
            ->where('info', 'like', '%"to":'.json_encode((string) $device->rustdesk_id).'%')
            ->latest('id')
            ->first();
    }

    /**
     * Record refusals hbbs posted. Each becomes an admin-only alarm (the
     * console source keeps it out of non-admin lists) naming the person,
     * where they sat and what they tried to reach.
     *
     * @param  list<array<string, mixed>>  $refusals
     * @return list<AlarmLog>
     */
    public static function recordRefusals(array $refusals): array
    {
        $rows = [];
        foreach ($refusals as $r) {
            $from = Device::query()->where('rustdesk_id', (string) ($r['from'] ?? ''))->first();
            $to = Device::query()->where('rustdesk_id', (string) ($r['to'] ?? ''))->first();
            $rows[] = AlarmLog::create([
                'rustdesk_id' => AlarmLog::CONSOLE_SOURCE,
                'typ' => self::ALARM_TYPE,
                'info' => json_encode([
                    'user' => (string) ($r['user_name'] ?? '') ?: 'nobody signed in',
                    'user_id' => (string) ($r['user_id'] ?? ''),
                    'from' => (string) ($r['from'] ?? ''),
                    'from_name' => $from?->alias ?: ($from?->hostname ?: ''),
                    'to' => (string) ($r['to'] ?? ''),
                    'to_name' => $to?->alias ?: ($to?->hostname ?: ''),
                    'ip' => (string) ($r['ip'] ?? ''),
                    'reason' => (string) ($r['reason'] ?? ''),
                ]),
            ]);
        }

        return $rows;
    }
}
