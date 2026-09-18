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
        @stack('scripts')
    </body>
</html>
