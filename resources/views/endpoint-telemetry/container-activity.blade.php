<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Container Activity Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Endpoint telemetry is advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Container activity telemetry is advisory-only. No container is stopped, paused, or killed automatically.
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">Total Activities (7d)</div>
                <div class="text-2xl font-bold text-green-300">{{ $summary['total_activities'] }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">Unique Containers</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $summary['unique_containers'] }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">Breakout Indicators</div>
                <div class="text-2xl font-bold text-red-400">{{ $summary['breakout_indicators'] }}</div>
            </div>
        </div>

        <div class="glass-card p-4 space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">By Namespace Type</h3>
            @forelse($summary['by_namespace_type'] as $row)
            <div class="flex justify-between text-xs text-gray-300 border-b border-gray-700/40 pb-1">
                <span class="font-mono text-green-300">{{ $row->namespace_type ?? 'unknown' }}</span>
                <span>{{ $row->count }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No container activity recorded.</p>
            @endforelse
        </div>

        @if($breakouts->isNotEmpty())
        <div class="glass-card p-4 space-y-2 border border-red-700/40">
            <h3 class="text-sm font-semibold text-red-300">Container Breakout Indicators</h3>
            @foreach($breakouts as $b)
            <div class="text-xs border-b border-gray-700/40 pb-2 space-y-0.5">
                <div class="flex justify-between">
                    <span class="font-mono text-red-300">{{ $b->container_id }}</span>
                    <span class="text-gray-500">{{ $b->namespace_type }}</span>
                </div>
                <div class="text-gray-400">{{ $b->process_name }} — {{ $b->occurred_at?->diffForHumans() }}</div>
            </div>
            @endforeach
        </div>
        @endif

    </div>
</x-app-layout>
