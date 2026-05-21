<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Operations Health Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Operational hardening and recovery tooling only. No autonomous infrastructure mutation.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">Platform Health Score</div>
                <div class="text-3xl font-bold {{ $score >= 0.8 ? 'text-green-400' : ($score >= 0.5 ? 'text-amber-400' : 'text-red-400') }}">
                    {{ number_format($score * 100) }}%
                </div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">Services Monitored</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $snapshots->count() }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">Active Warnings</div>
                <div class="text-2xl font-bold {{ count($warnings) > 0 ? 'text-amber-400' : 'text-green-400' }}">
                    {{ count($warnings) }}
                </div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">Critical Pressure</div>
                <div class="text-2xl font-bold {{ $pressure->where('state', 'critical')->count() > 0 ? 'text-red-400' : 'text-green-400' }}">
                    {{ $pressure->where('state', 'critical')->count() }}
                </div>
            </div>
        </div>

        @if(!empty($warnings))
        <div class="space-y-2">
            <h3 class="text-sm font-semibold text-amber-400">Active Warnings</h3>
            @foreach(array_slice($warnings, 0, 5) as $w)
            <div class="flex gap-3 items-start rounded border border-amber-400/20 bg-amber-900/10 px-3 py-2 text-xs">
                <span class="shrink-0 font-mono text-amber-400 uppercase">{{ $w['severity'] }}</span>
                <span class="text-amber-200">{{ $w['message'] }}</span>
            </div>
            @endforeach
        </div>
        @endif

        <div class="grid grid-cols-2 gap-4">
            <div>
                <h3 class="text-sm font-semibold text-cyan-200 mb-2">Service Health States</h3>
                @forelse($snapshots as $svc)
                <div class="flex items-center justify-between py-1 border-b border-gray-800 text-xs">
                    <span class="text-gray-300 font-mono">{{ $svc->service_name }}</span>
                    <span class="px-1.5 py-0.5 rounded text-xs
                        {{ $svc->health_state === 'healthy' ? 'bg-green-900/40 text-green-300' :
                           ($svc->health_state === 'degraded' ? 'bg-amber-900/40 text-amber-300' : 'bg-red-900/40 text-red-300') }}">
                        {{ $svc->health_state }}
                    </span>
                </div>
                @empty
                <p class="text-xs text-gray-500">No health data. Run <code>php artisan ops:startup-validate --record</code></p>
                @endforelse
            </div>

            <div>
                <h3 class="text-sm font-semibold text-cyan-200 mb-2">Stream Pressure</h3>
                @forelse($pressure as $p)
                <div class="flex items-center justify-between py-1 border-b border-gray-800 text-xs">
                    <span class="text-gray-300">{{ $p->service_name }}: {{ $p->metric_type }}</span>
                    <span class="px-1.5 py-0.5 rounded
                        {{ $p->state === 'normal' ? 'bg-green-900/40 text-green-300' :
                           ($p->state === 'warning' ? 'bg-amber-900/40 text-amber-300' : 'bg-red-900/40 text-red-300') }}">
                        {{ number_format($p->value) }} {{ $p->unit }}
                    </span>
                </div>
                @empty
                <p class="text-xs text-gray-500">No pressure metrics recorded yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
