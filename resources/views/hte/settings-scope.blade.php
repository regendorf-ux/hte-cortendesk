{{-- HTE (2026-10-16): per-person reach, enforced by the ID server. Included
     by settings-page.blade.php under Server access. --}}
@php $hteScope = \App\Hte\ScopeSettings::hteScopeStatus(); @endphp
<div class="form-check form-switch mt-3">
    <input class="form-check-input" type="checkbox" role="switch" id="hteScopeEnforce" wire:model="hteScopeEnforce">
    <label class="form-check-label" for="hteScopeEnforce">Each person may reach only the devices they are given</label>
</div>
<div class="form-text">Enforced by the ID server. A signed-in user can start a session only to a device this console shows them: the devices assigned to them and the device groups granted to them or to their user groups. Administrators reach every device. A client that is not signed in cannot start sessions. Refused attempts appear under Logs &rarr; Alarms and on the device's page. Takes effect within a few seconds; sessions already running are not cut. Needs <strong>Approved devices only</strong> and the HTE build of the ID server.</div>
<div class="form-text {{ $hteScope['tone'] }}" id="hte-scope-status"><i class="ri-shield-user-line me-1"></i>Per-person reach: {{ $hteScope['text'] }}</div>
@php $htePw = \App\Hte\Passwords::summary(); @endphp
<div class="form-check form-switch mt-3">
    <input class="form-check-input" type="checkbox" role="switch" id="htePasswords" wire:model="htePasswords">
    <label class="form-check-label" for="htePasswords">Each computer gets its own password, made and changed by the console</label>
</div>
<div class="d-flex align-items-center gap-2 mt-2">
    <label class="form-label mb-0" for="htePasswordDays">Change it</label>
    <select id="htePasswordDays" class="form-select form-select-sm" wire:model="htePasswordDays" style="max-width: 260px;">
        @foreach (\App\Hte\Passwords::DAY_CHOICES as $d => $label)
            <option value="{{ $d }}">{{ $label }}{{ $d ? ' ('.$d.')' : '' }}</option>
        @endforeach
    </select>
</div>
<div class="form-text">HTE Remote computers (Windows and Mac) take the new password at their next check-in and confirm it; until they do, the old one keeps working. A password is also changed at once when someone loses access to that computer. People never see it: each person's HTE Remote fills it in from their automatic "HTE Remote" address book.</div>
<div class="form-text {{ $htePw[0] }}" id="hte-password-status"><i class="ri-key-2-line me-1"></i>{{ $htePw[1] }}</div>
