<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }}</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="antialiased">

<div class="flex min-h-screen" x-data="{ mobileOpen: false }">

    {{-- ── Sidebar ──────────────────────────────────────────────────────── --}}
    @include('layouts.navigation')

    {{-- ── Mobile backdrop ─────────────────────────────────────────────── --}}
    <div
        x-show="mobileOpen"
        @click="mobileOpen = false"
        x-transition:enter="transition-opacity duration-200 ease-linear"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity duration-200 ease-linear"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-20 bg-black/60 backdrop-blur-sm lg:hidden"
        style="display:none"
    ></div>

    {{-- ── Main area ────────────────────────────────────────────────────── --}}
    <div class="flex flex-1 flex-col min-w-0 lg:pl-64">

        {{-- Mobile topbar --}}
        <div class="sticky top-0 z-10 flex items-center gap-3 h-14 px-4 border-b border-cyan-800/30 bg-[var(--bg-0)]/90 backdrop-blur-md lg:hidden">
            <button
                @click="mobileOpen = !mobileOpen"
                class="p-1.5 rounded-lg text-cyan-300 hover:bg-cyan-900/40 transition-colors"
                aria-label="Toggle sidebar"
            >
                <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
                <x-application-logo class="h-7 w-auto fill-current text-cyan-300" />
                <span class="text-sm font-bold text-cyan-100 tracking-tight">XDR Platform</span>
            </a>
        </div>

        {{-- Page header --}}
        @if (isset($header))
            <div class="px-4 pt-5 pb-4 sm:px-6 lg:px-8 border-b border-cyan-800/20">
                {{ $header }}
            </div>
        @endif

        {{-- Content --}}
        <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                {{ $slot }}
            </div>
        </main>

    </div>
</div>

</body>
</html>
