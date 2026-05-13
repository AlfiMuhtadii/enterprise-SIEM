<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Detector') }}</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="antialiased">
    <main class="relative min-h-screen overflow-hidden px-4 py-8 sm:px-6 lg:px-8">
        <div class="pointer-events-none absolute inset-0">
            <div class="float-slow absolute left-0 top-10 h-56 w-56 rounded-full bg-emerald-300/15 blur-3xl"></div>
            <div class="float-slow absolute right-0 top-20 h-72 w-72 rounded-full bg-sky-300/15 blur-3xl"></div>
            <div class="absolute bottom-0 left-1/3 h-64 w-64 rounded-full bg-cyan-300/10 blur-3xl"></div>
        </div>

        <div class="relative mx-auto flex min-h-[88vh] w-full max-w-6xl flex-col">
            <header class="flex items-center justify-between py-4">
                <div class="flex items-center gap-3">
                    <x-application-logo class="h-11 w-11 fill-current text-cyan-200" />
                    <div>
                        <p class="text-xs uppercase tracking-[0.2em] text-cyan-200/80">Security Telemetry</p>
                        <p class="text-lg font-semibold text-main-ui">Detector Lab</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-xl border border-cyan-200/35 bg-cyan-100/10 px-4 py-2 text-sm font-medium text-cyan-100 hover:bg-cyan-100/20">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-xl border border-cyan-200/35 bg-cyan-100/10 px-4 py-2 text-sm font-medium text-cyan-100 hover:bg-cyan-100/20">Login</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="rounded-xl border border-emerald-200/35 bg-emerald-300/90 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-200">Register</a>
                        @endif
                    @endauth
                </div>
            </header>

            <section class="my-auto grid items-center gap-6 py-10 lg:grid-cols-2">
                <div>
                    <p class="brand-chip">Enterprise Demo Ready</p>
                    <h1 class="mt-4 text-4xl font-semibold leading-tight text-main-ui sm:text-5xl">Modern Security Event Playground</h1>
                    <p class="mt-4 max-w-xl text-base text-muted-ui sm:text-lg">
                        Dummy app ini menghasilkan sinyal serangan realistis, mengalirkan telemetry terstruktur, dan menampilkan alert explainable di pipeline lokal.
                    </p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="{{ route('dashboard') }}" class="rounded-xl border border-emerald-200/35 bg-emerald-300/90 px-5 py-3 text-sm font-semibold text-slate-900 hover:bg-emerald-200">
                            Open Dashboard
                        </a>
                        <a href="/search?q=%27%20OR%201%3D1--" class="rounded-xl border border-cyan-200/35 bg-cyan-100/10 px-5 py-3 text-sm font-medium text-cyan-100 hover:bg-cyan-100/20">
                            Trigger Search Signal
                        </a>
                    </div>
                </div>

                <div class="glass-card p-5 sm:p-6">
                    <p class="text-sm uppercase tracking-[0.15em] text-cyan-200/80">Telemetry Features</p>
                    <div class="mt-4 grid gap-3">
                        <div class="metric-card">
                            <p class="text-sm text-cyan-100">Request Correlation</p>
                            <p class="mt-1 text-xs text-muted-ui"><span class="mono-ui">request_id</span> across event pipeline and alerts.</p>
                        </div>
                        <div class="metric-card">
                            <p class="text-sm text-cyan-100">Privacy-by-Design</p>
                            <p class="mt-1 text-xs text-muted-ui">HMAC hash for email/user-agent, no raw sensitive payload.</p>
                        </div>
                        <div class="metric-card">
                            <p class="text-sm text-cyan-100">Scalable Story</p>
                            <p class="mt-1 text-xs text-muted-ui">Postgres + Redpanda + ClickHouse + Grafana in local enterprise stack.</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
