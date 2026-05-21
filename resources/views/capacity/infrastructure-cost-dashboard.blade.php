<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Infrastructure Cost Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1">Capacity and performance governance are operational visibility controls only. No autonomous scaling, destructive retention purge, or hidden infrastructure mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> Cost estimates use relative units (not monetary values). Linear projection model. No autonomous infrastructure mutation executed.
        </div>

        @if($totalDaily)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-xs">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">Daily Cost (total)</div>
                <div class="text-2xl font-bold text-cyan-300">{{ number_format($totalDaily->daily_cost_estimate, 4) }} units</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">Monthly Estimate</div>
                <div class="text-2xl font-bold text-blue-300">{{ number_format($totalDaily->monthly_cost_estimate, 2) }} units</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">90-Day Projection</div>
                <div class="text-2xl font-bold text-purple-300">{{ number_format($totalDaily->projected_90d_cost, 2) }} units</div>
            </div>
        </div>
        @endif

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Cost Estimates ({{ $estimates->count() }})</h3>
            @forelse($estimates as $est)
            <div class="py-1.5 border-b border-gray-800 last:border-0 text-xs flex items-center gap-2">
                <span class="text-cyan-300 w-16">{{ $est->scope }}</span>
                <span class="text-gray-400 w-24">{{ $est->backend ?? '—' }}</span>
                <span class="text-gray-300">{{ number_format($est->daily_cost_estimate, 4) }}/day</span>
                <span class="text-gray-500">growth: {{ number_format($est->growth_rate_pct, 1) }}%</span>
                @if($est->is_advisory)<span class="ml-auto text-green-400/70">advisory</span>@endif
            </div>
            @empty
            <p class="text-xs text-gray-500">No cost estimates.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
