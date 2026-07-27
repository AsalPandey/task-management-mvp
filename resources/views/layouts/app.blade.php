<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Task Management') }}</title>

        <!-- Styles -->
        @stack('styles')
        <link rel="stylesheet" href="{{ asset('css/phase3.css') }}?v={{ filemtime(public_path('css/phase3.css')) }}">
        
        <!-- Scripts -->
        {{-- @vite(['resources/css/app.css', 'resources/js/app.js']) --}}
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="{{ asset('js/phase3.js') }}?v={{ filemtime(public_path('js/phase3.js')) }}" defer></script>
    </head>
    <body
        class="font-sans antialiased app-body"
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
