<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#2563eb">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Task Management MVP') }}">

        <title>{{ config('app.name', 'Task Management MVP') }}</title>

        <!-- Styles -->
        @stack('styles')
        <link rel="manifest" href="{{ url('/manifest.webmanifest') }}">
        <link rel="apple-touch-icon" sizes="192x192" href="{{ asset('icons/pwa-192.png') }}">
        <link rel="stylesheet" href="{{ asset('css/phase3.css') }}?v={{ filemtime(public_path('css/phase3.css')) }}">
        <link rel="stylesheet" href="{{ asset('css/pwa.css') }}?v={{ filemtime(public_path('css/pwa.css')) }}">
        
        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script src="{{ asset('js/phase3.js') }}?v={{ filemtime(public_path('js/phase3.js')) }}" defer></script>
        <script src="{{ asset('js/pwa.js') }}?v={{ filemtime(public_path('js/pwa.js')) }}" defer></script>
    </head>
    <body
        class="font-sans antialiased app-body"
        data-user-id="{{ auth()->id() }}"
        data-user-role="{{ auth()->user()?->role?->name }}"
        data-page="{{ request()->route()?->getName() }}"
        data-ui-version="phase-3a"
    >
        <a class="skip-link" href="#main-content">Skip to main content</a>
        <div class="min-h-screen bg-gray-100 app-shell">
            @include('partials.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main id="main-content" tabindex="-1">
                @yield('content')
            </main>

            @include('partials.mobile-bottom-navigation')
        </div>
        <div class="pwa-install-dialog" data-pwa-dialog hidden>
            <div class="pwa-install-dialog__backdrop" data-pwa-dismiss></div>
            <section class="pwa-install-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="pwaInstallTitle" aria-describedby="pwaInstallDescription">
                <button type="button" class="pwa-install-dialog__close" data-pwa-dismiss aria-label="Close installation prompt">&times;</button>
                <div class="pwa-install-dialog__icon" aria-hidden="true">TM</div>
                <h2 id="pwaInstallTitle">Install {{ config('app.name', 'Task Management MVP') }}</h2>
                <p id="pwaInstallDescription">Add {{ config('app.name', 'Task Management MVP') }} to your phone for faster access and a home-screen app experience.</p>
                <div class="pwa-install-dialog__actions">
                    <button type="button" class="btn-primary" data-pwa-install>Install App</button>
                    <button type="button" class="btn-secondary" data-pwa-dismiss>Not Now</button>
                </div>
                <button type="button" class="pwa-install-dialog__help-link" data-pwa-help>Having trouble installing?</button>
                <div class="pwa-install-dialog__guide" data-pwa-guide hidden></div>
            </section>
        </div>
        @stack('scripts')
    </body>
</html>
