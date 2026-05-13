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
    <div class="relative min-h-screen overflow-hidden px-4 py-10 sm:px-6">
        <div class="pointer-events-none absolute inset-0">
            <div class="float-slow absolute -left-16 top-20 h-52 w-52 rounded-full bg-cyan-300/15 blur-3xl"></div>
            <div class="float-slow absolute right-0 top-10 h-64 w-64 rounded-full bg-emerald-300/15 blur-3xl"></div>
            <div class="absolute bottom-0 left-1/2 h-72 w-72 -translate-x-1/2 rounded-full bg-sky-300/10 blur-3xl"></div>
        </div>

        <div class="relative mx-auto flex w-full max-w-md flex-col justify-center gap-5">
            <div class="mx-auto">
                <a href="/">
                    <x-application-logo class="h-16 w-16 fill-current text-cyan-200" />
                </a>
            </div>

            <div class="glass-card w-full overflow-hidden px-6 py-7 sm:px-7">
                {{ $slot }}
            </div>
        </div>
    </div>
</body>
</html>
