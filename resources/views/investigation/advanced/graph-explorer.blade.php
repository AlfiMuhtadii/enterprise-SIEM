<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Graph Explorer — {{ $session->name }}</h2>
        <p class="text-xs text-amber-400/80 mt-1">Advisory-only graph traversal. Hop depth: {{ $session->hop_depth }}.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> Graph traversal is read-only. No enforcement actions are triggered from graph exploration.
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Graph Nodes ({{ $graph['nodes']->count() }})</h3>
                <div class="space-y-1 max-h-64 overflow-y-auto">
                    @forelse($graph['nodes'] as $node)
                    <div class="flex items-center gap-2 py-1 border-b border-gray-800 last:border-0">
                        <span class="text-xs px-1.5 py-0.5 rounded bg-cyan-900/40 text-cyan-300">{{ $node->node_type }}</span>
                        <span class="text-xs text-gray-300 truncate">{{ $node->label }}</span>
                        @if($node->is_pivot_origin)
                        <span class="text-xs text-yellow-400">[origin]</span>
                        @endif
                        <span class="text-xs text-gray-500 ml-auto">risk: {{ number_format($node->risk_score, 1) }}</span>
                    </div>
                    @empty
                    <p class="text-xs text-gray-500">No nodes in this session.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Graph Edges ({{ $graph['edges']->count() }})</h3>
                <div class="space-y-1 max-h-64 overflow-y-auto">
                    @forelse($graph['edges'] as $edge)
                    <div class="py-1 border-b border-gray-800 last:border-0">
                        <div class="flex items-center gap-1 text-xs">
                            <span class="text-gray-400 truncate max-w-28">{{ $edge->from_node_id }}</span>
                            <span class="text-purple-400">→</span>
                            <span class="text-gray-400 truncate max-w-28">{{ $edge->to_node_id }}</span>
                        </div>
                        <div class="text-xs text-purple-300 mt-0.5">{{ $edge->relationship_type }} (conf: {{ number_format($edge->confidence, 2) }})</div>
                    </div>
                    @empty
                    <p class="text-xs text-gray-500">No edges in this session.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-2">Session Info</h3>
            <dl class="grid grid-cols-3 gap-2 text-xs">
                <div><dt class="text-gray-400">Status</dt><dd class="text-cyan-300">{{ $session->status }}</dd></div>
                <div><dt class="text-gray-400">Focus Entity</dt><dd class="text-gray-200">{{ $session->focus_entity_id ?? '—' }}</dd></div>
                <div><dt class="text-gray-400">Hop Depth</dt><dd class="text-gray-200">{{ $session->hop_depth }}</dd></div>
            </dl>
        </div>

        <a href="{{ route('investigation.advanced.workspace') }}" class="text-xs text-gray-400 hover:text-gray-300">← Back to Workspace</a>

    </div>
</x-app-layout>
