<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Cross-Domain Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Pivot across {{ count($domains) }} hunt domains for cross-domain correlation. Advisory-only.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> Cross-domain exploration is read-only. All results are advisory. No enforcement or isolation actions are triggered.
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Available Domains</div>
                <div class="text-2xl font-bold text-cyan-300">{{ count($domains) }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Graph Nodes</div>
                <div class="text-2xl font-bold text-blue-300">{{ $nodeCount }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Graph Edges</div>
                <div class="text-2xl font-bold text-purple-300">{{ $edgeCount }}</div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Available Hunt Domains</h3>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($domains as $domain)
                    <span class="px-2 py-1 text-xs rounded bg-gray-800 text-gray-300 border border-gray-700">{{ $domain }}</span>
                    @endforeach
                </div>
            </div>

            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Node Types Available</h3>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($nodeTypes as $nt)
                    <span class="px-2 py-1 text-xs rounded bg-cyan-900/30 text-cyan-300 border border-cyan-700/40">{{ $nt }}</span>
                    @endforeach
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
