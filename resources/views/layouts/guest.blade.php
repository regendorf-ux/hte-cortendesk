{{-- HTE Remote: CortenDesk's guest layout (sign-in, password reset, 2FA) in HTE's theme, option B of
     2026-10-15: the programs' welcome card on HTE Remote's navy. HTE: this whole file replaces
     CortenDesk's (HTE.md); until 1.14.0-hte.4 it was bind-mounted from HTE-RustDesk/brand. --}}
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8"/>
    <title>@yield('title', 'Sign In') | {{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ \App\Support\Asset::url('assets/images/hte-remote.png') }}">

    <script src="{{ \App\Support\Asset::url('assets/js/theme-init.js') }}"></script>
    <link href="{{ \App\Support\Asset::url('assets/vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/vendor/remixicon/remixicon.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/fonts/figtree/figtree.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/css/spacing.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/css/shell.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/css/cortendesk.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/css/hte-auth.css') }}" rel="stylesheet" type="text/css"/>
</head>

<body class="hte-auth position-relative">

    <div class="hte-auth-page">
        @yield('content')
        <p class="hte-auth-note">HTE Remote &mdash; remote support for HTE, Inc.</p>
    </div>

    <script src="{{ \App\Support\Asset::url('assets/vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ \App\Support\Asset::url('assets/js/shell.js') }}"></script>
</body>
</html>
