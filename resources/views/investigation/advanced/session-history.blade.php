<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Investigation Session History</h2>
        <p class="text-xs text-amber-400/80 mt-1">All investigation sessions — advisory-only, audit-visible.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach([
                ['Total Sessions', $stats['total_sessions'], 'text-cyan-300'],
                ['Active', $stats['active_sessions'], 'text-green-300'],
                ['Graph Nodes', $stats['total_graph_nodes'], 'text-blue-300'],
                ['Evidence Links', $stats['total_evidence_links'], 'text-purple-300'],
            ] as [$label, $value, $color])
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $value }}</div>
            </div>
            @endforeach
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">All Sessions ({{ $sessions->count() }})</h3>
            <div class="space-y-2">
                @forelse($sessions as $session)
                <div class="flex items-center justify-between py-2 border-b border-gray-800 last:border-0 text-xs">
                    <div>
                        <span class="text-cyan-300 font-medium">{{ $session->name }}</span>
                        <span class="ml-2 font-mono text-gray-500">{{ $session->session_id }}</span>
                        <span class="ml-2 px-1.5 py-0.5 rounded
                            @if($session->status === 'active') bg-green-900/40 text-green-300
                            @elseif($session->status === 'completed') bg-blue-900/40 text-blue-300
                            @elseif($session->status === 'abandoned') bg-red-900/40 text-red-300
                            @else bg-gray-700 text-gray-400 @endif">
                            {{ $session->status }}
                        </span>
                    </div>
                    <div class="flex items-center gap-3 text-gray-400">
                        @if($session->focus_entity_id)
                        <span>focus: {{ $session->focus_entity_id }}</span>
                        @endif
                        <span>{{ $session->updated_at?->format('Y-m-d H:i') ?? '—' }}</span>
                        @if($session->status === 'active')
                        <a href="{{ route('investigation.advanced.graph-explorer', ['sessionId' => $session->session_id]) }}" class="text-blue-400 hover:text-blue-300">Open</a>
                        @endif
                    </div>
                </div>
                @empty
                <p class="text-xs text-gray-500">No sessions yet.</p>
                @endforelse
            </div>
        </div>

        <a href="{{ route('investigation.advanced.workspace') }}" class="text-xs text-gray-400 hover:text-gray-300">← Back to Workspace</a>

    </div>
</x-app-layout>
