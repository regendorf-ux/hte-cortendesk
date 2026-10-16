{{-- HTE (2026-10-16): per-person reach, enforced by the ID server. Included
     by settings-page.blade.php under Server access. --}}
@php $hteScope = \App\Hte\ScopeSettings::hteScopeStatus(); @endphp
<div class="form-check form-switch mt-3">
    <input class="form-check-input" type="checkbox" role="switch" id="hteScopeEnforce" wire:model="hteScopeEnforce">
    <label class="form-check-label" for="hteScopeEnforce">Each person may reach only the devices they are given</label>
</div>
<div class="form-text">Enforced by the ID server. A signed-in user can start a session only to a device this console shows them: the devices assigned to them and the device groups granted to them or to their user groups. Administrators reach every device. A client that is not signed in cannot start sessions. Refused attempts appear under Logs &rarr; Alarms and on the device's page. Takes effect within a few seconds; sessions already running are not cut. Needs <strong>Approved devices only</strong> and the HTE build of the ID server.</div>
<div class="form-text {{ $hteScope['tone'] }}" id="hte-scope-status"><i class="ri-shield-user-line me-1"></i>Per-person reach: {{ $hteScope['text'] }}</div>
