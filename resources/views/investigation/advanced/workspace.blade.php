<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Investigation Workspace</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory-only — no autonomous enforcement, no evidence deletion, no hidden graph mutation.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> All investigation actions are advisory-only, replay-safe, and operator-controlled. No autonomous enforcement is performed.
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('investigation.advanced.graph-explorer', ['sessionId' => 'current']) }}" class="px-3 py-1.5 text-xs rounded bg-cyan-900/30 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/50">Graph Explorer</a>
            <a href="{{ route('investigation.advanced.multi-hop-pivot') }}" class="px-3 py-1.5 text-xs rounded bg-blue-900/30 text-blue-300 border border-blue-700/40 hover:bg-blue-900/50">Multi-Hop Pivot</a>
            <a href="{{ route('investigation.advanced.retrospective-hunt') }}" class="px-3 py-1.5 text-xs rounded bg-green-900/30 text-green-300 border border-green-700/40 hover:bg-green-900/50">Retrospective Hunt</a>
            <a href="{{ route('investigation.advanced.attack-investigation-map') }}" class="px-3 py-1.5 text-xs rounded bg-purple-900/30 text-purple-300 border border-purple-700/40 hover:bg-purple-900/50">ATT&CK Map</a>
            <a href="{{ route('investigation.advanced.cross-domain-explorer') }}" class="px-3 py-1.5 text-xs rounded bg-orange-900/30 text-orange-300 border border-orange-700/40 hover:bg-orange-900/50">Cross-Domain</a>
            <a href="{{ route('investigation.advanced.session-history') }}" class="px-3 py-1.5 text-xs rounded bg-gray-800 text-gray-300 border border-gray-600 hover:bg-gray-700">Session History</a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach([
                ['Active Sessions', $stats['active_sessions'], 'text-cyan-300'],
                ['Total Sessions', $stats['total_sessions'], 'text-blue-300'],
                ['Graph Nodes', $stats['total_graph_nodes'], 'text-green-300'],
                ['Graph Edges', $stats['total_graph_edges'], 'text-purple-300'],
                ['Timeline Events', $stats['total_timeline_events'], 'text-orange-300'],
                ['Evidence Links', $stats['total_evidence_links'], 'text-yellow-300'],
                ['Retro Queries', $stats['total_retrospective_queries'], 'text-gray-300'],
                ['Executed Queries', $stats['executed_queries'], 'text-teal-300'],
            ] as [$label, $value, $color])
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $value }}</div>
            </div>
            @endforeach
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Active Investigation Sessions</h3>
            @forelse($sessions->where('status', 'active') as $session)
            <div class="flex items-center justify-between py-2 border-b border-gray-800 last:border-0">
                <div>
                    <span class="text-sm text-cyan-300 font-medium">{{ $session->name }}</span>
                    <span class="ml-2 text-xs text-gray-400">{{ $session->session_id }}</span>
                </div>
                <a href="{{ route('investigation.advanced.graph-explorer', ['sessionId' => $session->session_id]) }}" class="text-xs text-blue-400 hover:text-blue-300">Open Graph</a>
            </div>
            @empty
            <p class="text-xs text-gray-500">No active sessions.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
