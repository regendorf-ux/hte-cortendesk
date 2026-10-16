{{-- HTE (2026-10-16): who can reach this device, for administrators. Included
     by device-detail.blade.php under the Device card. --}}
@if (auth()->user()?->is_admin && ! $device->trashed())
    @php
        $hteSections = \App\Hte\Reach::sections($device);
        $hteCount = collect($hteSections)->sum(fn ($s) => count($s['users']));
        $hteEnforced = \App\Hte\Reach::enforced();
        $hteLast = \App\Hte\Reach::lastRefusal($device);
        $hteLastInfo = $hteLast ? (json_decode((string) $hteLast->info, true) ?: []) : [];
    @endphp
    <div class="card" id="hte-reach">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Who can reach this device</h5>
            @if ($hteEnforced)
                <span class="badge bg-success-subtle text-success rd-status-block" title="The ID server refuses everyone else"><i class="ri-shield-check-line me-1"></i>Enforced</span>
            @else
                <span class="badge bg-secondary-subtle text-secondary rd-status-block" title="Settings → Server: Each person may reach only the devices they are given">Not enforced</span>
            @endif
        </div>
        <div class="card-body pt-0">
            <dl class="rd-deflist">
                @forelse ($hteSections as $section)
                    <div class="rd-def">
                        <dt>{{ $section['label'] }}@if ($section['sub'])<br><span class="fs-12 text-muted">{{ $section['sub'] }}</span>@endif</dt>
                        <dd>@foreach ($section['users'] as $u)<a href="{{ route('users.show', $u) }}" class="text-reset" title="{{ $u->username }}">{{ $u->displayName() }}</a>@if (! $loop->last)<br>@endif @endforeach</dd>
                    </div>
                @empty
                    <div class="rd-def"><dt>Nobody</dt><dd>—</dd></div>
                @endforelse
                @php [$htePwTone, $htePwText, $htePwSub, $htePwRotate] = \App\Hte\Passwords::rowFor($device); @endphp
                <div class="rd-def" id="hte-password-row"><dt>Password</dt>
                    <dd class="{{ $htePwTone }}">{{ $htePwText }}<br><span class="fs-12 text-muted">{{ $htePwSub }}@if ($htePwRotate) · <a href="#" wire:click.prevent="hteRotatePassword">change now</a>@endif</span></dd>
                </div>
            </dl>
            <div class="form-text mt-2">
                {{ $hteCount }} {{ $hteCount === 1 ? 'person' : 'people' }}. Grants change under Users and Groups and take effect within a few seconds.
                @if ($hteLast)
                    Last refusal: <strong>{{ $hteLastInfo['user'] ?? '' }}</strong>
                    from {{ $hteLastInfo['from'] ?? '' }}@if (! empty($hteLastInfo['from_name'])) {{ $hteLastInfo['from_name'] }}@endif,
                    {{ $hteLast->created_at?->diffForHumans() }}.
                @endif
            </div>
        </div>
    </div>
@endif
