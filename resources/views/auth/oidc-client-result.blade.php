@extends('layouts.guest')

@section('title', $ok ? 'Signed In' : 'Sign-In Failed')

@section('content')
    <div class="card">

        <div class="card-header rd-auth-head py-3 text-center d-flex align-items-center justify-content-center">
            <a href="{{ url('/') }}" class="rd-auth-brand mb-0">
                <img src="{{ asset('assets/images/hte-remote.png') }}" alt="HTE Remote" width="60" height="60" class="rd-auth-brand-logo"> {{-- HTE --}}
                <span class="rd-auth-brand-wordmark">HTE <span>Remote</span></span> {{-- HTE --}}
            </a>
        </div>

        <div class="card-body p-4 text-center">
            <span class="rd-auth-icon {{ $ok ? 'rd-tone-green' : 'rd-tone-red' }}">
                <i class="{{ $ok ? 'ri-checkbox-circle-line' : 'ri-error-warning-line' }}"></i>
            </span>

            <h4 class="rd-auth-title">
                {{ $ok ? 'Signed in to HTE Remote' : 'Sign-in failed' }}
            </h4>

            <p class="rd-auth-sub mb-0">{{ $message }}</p>

            @unless ($ok)
                <p class="rd-auth-foot-note mt-3">
                    Return to HTE Remote and try again, or contact your administrator.
                </p>
            @endunless
        </div>
    </div>
@endsection
