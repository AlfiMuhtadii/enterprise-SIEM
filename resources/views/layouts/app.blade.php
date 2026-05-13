<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Scripts -->
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="antialiased">
    <div class="min-h-screen">
        @include('layouts.navigation')

        @if (isset($header))
            <header class="px-4 pt-7 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-7xl">
                    <div class="glass-card px-6 py-4 sm:px-8">
                        {{ $header }}
                    </div>
                </div>
            </header>
        @endif

        <main class="px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
