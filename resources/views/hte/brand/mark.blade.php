{{-- HTE Remote's mark for the console chrome (2026-10-16). $size: lg (sidebar, icon + wordmark),
     sm (condensed sidebar), bar (top bar on phones). --}}
@php $hteIcon = \App\Support\Asset::url('assets/images/hte-remote.png'); @endphp
@if (($size ?? 'lg') === 'lg')
    <span class="hte-mark"><img src="{{ $hteIcon }}" alt=""><span class="hte-mark-name">HTE <span>Remote</span></span></span>
@elseif ($size === 'sm')
    <img src="{{ $hteIcon }}" alt="HTE Remote" class="hte-mark-icon mx-auto d-block"/>
@else
    <img src="{{ $hteIcon }}" alt="HTE Remote" class="hte-mark-icon">
@endif
