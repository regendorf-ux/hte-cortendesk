<?php

namespace App\Hte;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\AppriseNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTE (2026-10-16): POST /api/server/refusals, called by the HTE build of
 * hbbs behind VerifyServerSecret. Each refusal becomes an alarm, and a
 * security-alarm notification when those are set up.
 */
class RefusalsController extends Controller
{
    public const MAX = 500;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refusals' => ['required', 'array', 'max:'.self::MAX],
            'refusals.*.user_id' => ['nullable', 'string', 'max:40'],
            'refusals.*.user_name' => ['nullable', 'string', 'max:255'],
            'refusals.*.from' => ['nullable', 'string', 'max:100'],
            'refusals.*.to' => ['required', 'string', 'max:100'],
            'refusals.*.ip' => ['nullable', 'string', 'max:64'],
            'refusals.*.reason' => ['nullable', 'string', 'max:100'],
            'refusals.*.at' => ['nullable', 'integer', 'min:0'],
        ]);

        $alarms = Reach::recordRefusals($data['refusals']);

        $notifications = app(AppriseNotifications::class);
        foreach ($alarms as $alarm) {
            $info = json_decode((string) $alarm->info, true) ?: [];
            $notifications->sendAfterResponse(
                'security.alarm',
                $alarm->typeLabel(),
                ($info['user'] ?? 'Someone').' tried to reach device '.($info['to'] ?? '').' from '.($info['from'] ?? '').'; the ID server refused it.',
                'alarm:'.$alarm->id,
                Device::query()->where('rustdesk_id', (string) ($info['to'] ?? ''))->first(),
            );
        }

        return response()->json(['recorded' => count($alarms)]);
    }
}
