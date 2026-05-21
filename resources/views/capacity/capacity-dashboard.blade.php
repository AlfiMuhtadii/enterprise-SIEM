<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Capacity Governance Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Capacity and performance governance are operational visibility controls only. No autonomous scaling, destructive retention purge, or hidden infrastructure mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Capacity and performance governance are operational visibility controls only. No autonomous scaling, destructive retention purge, or hidden infrastructure mutation is executed.
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('capacity.replay-economics') }}" class="px-3 py-1.5 text-xs rounded bg-orange-900/30 text-orange-300 border border-orange-700/40 hover:bg-orange-900/50">Replay Economics</a>
            <a href="{{ route('capacity.query-performance') }}" class="px-3 py-1.5 text-xs rounded bg-blue-900/30 text-blue-300 border border-blue-700/40 hover:bg-blue-900/50">Query Performance</a>
            <a href="{{ route('capacity.storage-capacity') }}" class="px-3 py-1.5 text-xs rounded bg-purple-900/30 text-purple-300 border border-purple-700/40 hover:bg-purple-900/50">Storage Capacity</a>
            <a href="{{ route('capacity.cardinality') }}" class="px-3 py-1.5 text-xs rounded bg-red-900/30 text-red-300 border border-red-700/40 hover:bg-red-900/50">Cardinality</a>
            <a href="{{ route('capacity.replay-amplification') }}" class="px-3 py-1.5 text-xs rounded bg-yellow-900/30 text-yellow-300 border border-yellow-700/40 hover:bg-yellow-900/50">Amplification</a>
            <a href="{{ route('capacity.partition-pressure') }}" class="px-3 py-1.5 text-xs rounded bg-cyan-900/30 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/50">Partitions</a>
            <a href="{{ route('capacity.projection') }}" class="px-3 py-1.5 text-xs rounded bg-green-900/30 text-green-300 border border-green-700/40 hover:bg-green-900/50">Projections</a>
            <a href="{{ route('capacity.cost') }}" class="px-3 py-1.5 text-xs rounded bg-gray-800 text-gray-300 border border-gray-600 hover:bg-gray-700">Cost</a>
        </div>

        <div class="grid grid-cols-4 gap-4">
            @foreach([
                ['Critical Telemetry', $stats['critical_telemetry'], 'text-red-300'],
                ['Unbounded Replays', $stats['unbounded_replays'], 'text-orange-300'],
                ['Degraded Queries', $stats['degraded_queries'], 'text-yellow-300'],
                ['Critical Storage', $stats['critical_storage'], 'text-red-400'],
                ['Exceeded Amplification', $stats['exceeded_amplification'], 'text-orange-400'],
                ['Total Projections', $stats['total_projections'], 'text-green-300'],
                ['Cost Estimates', $stats['total_cost_estimates'], 'text-gray-300'],
                ['Cardinality Reports', $stats['total_cardinality_reports'], 'text-purple-300'],
            ] as [$label, $value, $color])
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $value }}</div>
            </div>
            @endforeach
        </div>

        <div class="grid grid-cols-3 gap-4 text-xs">
            <div class="rounded border border-green-700/30 bg-green-900/10 px-4 py-3">
                <div class="text-green-300 font-medium">Advisory Only</div>
                <div class="text-green-200 mt-1">{{ $stats['advisory_only'] ? 'YES' : 'NO' }}</div>
            </div>
            <div class="rounded border border-green-700/30 bg-green-900/10 px-4 py-3">
                <div class="text-green-300 font-medium">Autonomous Scaling</div>
                <div class="text-green-200 mt-1">{{ $stats['autonomous_scaling'] ? 'ENABLED (WARNING)' : 'DISABLED' }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400 font-medium">Partition Snapshots</div>
                <div class="text-gray-200 mt-1">{{ $stats['total_partition_snapshots'] }}</div>
            </div>
        </div>

    </div>
</x-app-layout>
