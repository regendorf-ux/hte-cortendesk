<?php

namespace App\Hte;

use App\Models\ConsoleAudit;
use App\Models\Device;

/** HTE (2026-10-16): the device page's "change now" (administrators). A Livewire trait on DeviceDetail. */
trait DevicePasswordActions
{
    public function hteRotatePassword(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
        $device = Device::query()->findOrFail($this->deviceId);
        Passwords::issue($device, 'changed by '.auth()->user()->username);
        ConsoleAudit::record('hte.password', 'New password for '.$device->rustdesk_id.' (by hand)', 'device', (string) $device->rustdesk_id);
    }
}
