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
        <meta name="apple-mobile-web-app-title" content="Task Management">

        <title>{{ config('app.name', 'Task Management') }}</title>
        <link rel="manifest" href="{{ url('/manifest.webmanifest') }}">
        <link rel="apple-touch-icon" sizes="192x192" href="{{ asset('icons/pwa-192.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <link rel="stylesheet" href="{{ asset('css/phase3.css') }}?v={{ filemtime(public_path('css/phase3.css')) }}">
        <link rel="stylesheet" href="{{ asset('css/pwa.css') }}?v={{ filemtime(public_path('css/pwa.css')) }}">
        <script src="{{ asset('js/pwa.js') }}?v={{ filemtime(public_path('js/pwa.js')) }}" defer></script>
    </head>
    <body class="font-sans text-gray-900 antialiased guest-body" data-ui-version="phase-3a">
        <a class="skip-link" href="#main-content">Skip to sign in</a>
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 guest-shell">
            <div class="auth-brand">
                <a href="/" aria-label="Task Management home">
                    <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
                </a>
                <div>
                    <strong>Task Management</strong>
                    <span>Keep work moving, one clear step at a time.</span>
                </div>
            </div>

            <main id="main-content" class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg auth-card" tabindex="-1">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
