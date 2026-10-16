<?php

namespace App\Hte;

use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\ClientToken;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * HTE (2026-10-16): close sessions that per-person reach no longer allows.
 *
 * The ID server only checks a session when it starts. This sweep, run every
 * fifteen seconds, looks at every open session while enforcement is on and
 * asks the controlled device to close it (the console's own disconnect, the
 * one Active Sessions uses) when nobody who may reach the device is signed in
 * on the device that opened it. That covers a grant removed, a person moved
 * out of a user group, a device moved to another group, a person disabled or
 * logged out, and signing out of the client mid-session.
 *
 * Who opened a session is known by device (`from_peer`). The people signed in
 * there are the holders of its live client tokens; if ANY of them may reach
 * the target the session stays (two people sharing a PC share its access).
 * A `from_peer` that is not a registered device (the console's web client) is
 * left alone: the console cannot tell who is behind it.
 */
class ReachSweep extends Command
{
    protected $signature = 'hte:reach-sweep';

    protected $description = 'HTE: close open sessions that per-person reach no longer allows';

    /** Alarm type for a session the console closed because access changed. */
    public const ALARM_TYPE = 103;

    public function handle(): int
    {
        if (! Reach::enforced()) {
            return self::SUCCESS;
        }

        $closed = 0;
        AuditConnection::query()
            ->whereNull('closed_at')
            ->whereNull('disconnect_requested_at')
            ->whereNotNull('conn_id')
            ->whereNotNull('from_peer')->where('from_peer', '!=', '')
            ->orderBy('id')
            ->each(function (AuditConnection $session) use (&$closed) {
                $why = self::verdict($session);
                if ($why === null) {
                    return;
                }
                $session->forceFill([
                    'disconnect_requested_at' => now(),
                    'disconnect_sent_at' => null,
                    'disconnect_requested_by' => null,
                ])->save();
                AlarmLog::create([
                    'rustdesk_id' => AlarmLog::CONSOLE_SOURCE,
                    'typ' => self::ALARM_TYPE,
                    'conn_id' => $session->conn_id,
                    'info' => json_encode(['from' => (string) $session->from_peer, 'to' => (string) $session->rustdesk_id, 'reason' => $why]),
                ]);
                ConsoleAudit::system(
                    'session.disconnect',
                    'Closed session '.$session->conn_id.' from '.$session->from_peer.' on '.$session->rustdesk_id.': '.$why,
                    'device',
                    (string) $session->rustdesk_id,
                );
                $closed++;
            });

        if ($closed > 0) {
            $this->info("Closed {$closed} session(s).");
        }

        return self::SUCCESS;
    }

    /** Why this session must close, or null when it may stay. */
    public static function verdict(AuditConnection $session): ?string
    {
        $from = (string) $session->from_peer;
        if (! Device::query()->withTrashed()->where('rustdesk_id', $from)->exists()) {
            return null; // the web client or something the console does not know
        }

        $holders = User::query()
            ->whereIn('id', ClientToken::query()
                ->where('device_id', $from)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->pluck('user_id'))
            ->get();
        $active = $holders->where('is_active', true);

        if ($active->isEmpty()) {
            return $holders->isEmpty() ? 'nobody is signed in on '.$from : 'the person signed in on '.$from.' is disabled';
        }

        foreach ($active as $user) {
            if ($user->is_admin
                || Device::query()->visibleTo($user)->where('rustdesk_id', (string) $session->rustdesk_id)->exists()) {
                return null;
            }
        }

        return $active->pluck('username')->implode(', ').' may no longer reach '.$session->rustdesk_id;
    }
}
