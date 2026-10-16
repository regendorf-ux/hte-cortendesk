<?php

// HTE (2026-10-16): per-person reach. The fixture: Osvaldo (admin), María
// (owns the front desk), Juan (in user group Support, which holds device
// group Accounting PCs), Luis (nothing), the front desk and the server in
// Accounting PCs, a pending laptop, and a client token per person.

use App\Hte\Reach;
use App\Models\AlarmLog;
use App\Models\ClientToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\DB;

function hteFixture(): array
{
    $osvaldo = User::factory()->create(['username' => 'oduran', 'name' => 'Osvaldo Durán', 'is_admin' => true]);
    $maria = User::factory()->create(['username' => 'mperez', 'name' => 'María Pérez']);
    $juan = User::factory()->create(['username' => 'jrivera', 'name' => 'Juan Rivera']);
    $luis = User::factory()->create(['username' => 'lgomez', 'name' => 'Luis Gómez']);

    $accounting = DeviceGroup::create(['name' => 'Accounting PCs']);
    $support = UserGroup::create(['name' => 'Support']);
    DB::table('user_group_user')->insert(['user_group_id' => $support->id, 'user_id' => $juan->id]);
    DB::table('device_group_user_group')->insert(['device_group_id' => $accounting->id, 'user_group_id' => $support->id]);

    $front = Device::create(['rustdesk_id' => '100000001', 'uuid' => 'uuid-100000001', 'hostname' => 'office-pc', 'status' => Device::STATUS_ACTIVE,
        'user_id' => $maria->id, 'device_group_id' => $accounting->id, 'can_initiate' => false]);
    $server = Device::create(['rustdesk_id' => '555000111', 'uuid' => 'uuid-555000111', 'hostname' => 'hte-12', 'status' => Device::STATUS_ACTIVE,
        'device_group_id' => $accounting->id, 'can_initiate' => true]);
    $pending = Device::create(['rustdesk_id' => '777000222', 'uuid' => 'uuid-777000222', 'hostname' => 'new-laptop', 'status' => Device::STATUS_PENDING,
        'device_group_id' => $accounting->id, 'can_initiate' => true]);
    $mac = Device::create(['rustdesk_id' => '100000002', 'uuid' => 'uuid-100000002', 'hostname' => 'admin-mac', 'status' => Device::STATUS_ACTIVE,
        'user_id' => $osvaldo->id, 'can_initiate' => true]);

    $tokens = [];
    foreach (['oduran' => $osvaldo, 'mperez' => $maria, 'jrivera' => $juan, 'lgomez' => $luis] as $name => $user) {
        $tokens[$name] = ClientToken::issue($user, ['id' => $mac->rustdesk_id])->token;
    }

    return compact('osvaldo', 'maria', 'juan', 'luis', 'accounting', 'support', 'front', 'server', 'pending', 'mac', 'tokens');
}

test('the policy carries no scope while the setting is off', function () {
    hteFixture();
    $block = Reach::policyBlock();
    expect($block['enforce'])->toBeFalse()
        ->and((array) $block['users'])->toBe([])
        ->and((array) $block['token_users'])->toBe([]);

    $policy = app(\App\Services\ServerLink::class)->policy();
    expect($policy['scope']['enforce'])->toBeFalse();
});

test('the policy maps every active person to the devices they see', function () {
    $f = hteFixture();
    Setting::put(Reach::SETTING, '1');

    $block = Reach::policyBlock();
    $users = (array) $block['users'];
    expect($block['enforce'])->toBeTrue();

    expect($users[(string) $f['osvaldo']->id])->toBe(['name' => 'oduran', 'all' => true, 'devices' => []]);
    // Owner of the front desk, nothing else.
    expect($users[(string) $f['maria']->id]['all'])->toBeFalse()
        ->and($users[(string) $f['maria']->id]['devices'])->toBe(['100000001']);
    // Support holds Accounting PCs: the front desk and the server, not the pending laptop.
    expect($users[(string) $f['juan']->id]['devices'])->toBe(['100000001', '555000111']);
    expect($users[(string) $f['luis']->id]['devices'])->toBe([]);

    $tokenUsers = (array) $block['token_users'];
    expect($tokenUsers[hash('sha256', $f['tokens']['mperez'])])->toBe((string) $f['maria']->id)
        ->and($tokenUsers[hash('sha256', $f['tokens']['oduran'])])->toBe((string) $f['osvaldo']->id);
});

test('a disabled person and an expired token drop out of the scope', function () {
    $f = hteFixture();
    Setting::put(Reach::SETTING, '1');
    $f['luis']->update(['is_active' => false]);
    ClientToken::where('token', $f['tokens']['jrivera'])->update(['expires_at' => now()->subMinute()]);

    $block = Reach::policyBlock();
    expect((array) $block['users'])->not->toHaveKey((string) $f['luis']->id);
    $tokenUsers = (array) $block['token_users'];
    expect($tokenUsers)->not->toHaveKey(hash('sha256', $f['tokens']['lgomez']))
        ->and($tokenUsers)->not->toHaveKey(hash('sha256', $f['tokens']['jrivera']))
        ->and($tokenUsers)->toHaveKey(hash('sha256', $f['tokens']['mperez']));
});

test('the device page lists who can reach it and how', function () {
    $f = hteFixture();
    $sections = Reach::sections($f['front']->fresh());
    $flat = array_map(fn ($s) => [$s['label'], $s['sub'], array_map(fn ($u) => $u->username, $s['users'])], $sections);
    expect($flat)->toBe([
        ['Administrators', null, ['oduran']],
        ['Owner', null, ['mperez']],
        ['Group Accounting PCs', 'via user group Support', ['jrivera']],
    ]);
    expect(Reach::usernames($f['server']->fresh()))->toBe(['oduran', 'jrivera']);
    // A person is listed once: Juan granted the group directly too stays under the first way in.
    DB::table('device_group_user')->insert(['device_group_id' => $f['accounting']->id, 'user_id' => $f['maria']->id]);
    expect(Reach::usernames($f['front']->fresh()))->toBe(['oduran', 'mperez', 'jrivera']);
});

test('hbbs reports a refusal and the console keeps it as an admin-only alarm', function () {
    $f = hteFixture();
    $response = $this->withToken('test-link-secret')->postJson('/api/server/refusals', ['refusals' => [
        ['user_id' => (string) $f['luis']->id, 'user_name' => 'lgomez', 'from' => '100000002', 'to' => '100000001',
            'ip' => '203.0.113.9', 'reason' => 'outside scope', 'at' => time()],
    ]]);
    $response->assertOk()->assertJson(['recorded' => 1]);

    $alarm = AlarmLog::latest('id')->first();
    expect($alarm->typ)->toBe(Reach::ALARM_TYPE)
        ->and($alarm->rustdesk_id)->toBe(AlarmLog::CONSOLE_SOURCE)
        ->and($alarm->typeLabel())->toContain('outside the person')
        ->and($alarm->infoPairs())->toMatchArray(['user' => 'lgomez', 'to' => '100000001', 'to_name' => 'office-pc', 'from_name' => 'admin-mac']);

    expect(Reach::lastRefusal($f['front'])?->id)->toBe($alarm->id)
        ->and(Reach::lastRefusal($f['server']))->toBeNull();

    $this->withToken('wrong')->postJson('/api/server/refusals', ['refusals' => []])->assertStatus(401);
});
