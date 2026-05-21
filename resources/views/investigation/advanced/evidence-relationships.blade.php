<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Evidence Relationships</h2>
        <p class="text-xs text-amber-400/80 mt-1">Append-only evidence linkage for investigation {{ $investigationId }}.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> Evidence links are append-only and cannot be deleted. All links are advisory and operator-visible.
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Evidence Links</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $evidenceLinks->count() }}</div>
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

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Evidence Links</h3>
            <div class="space-y-2">
                @forelse($evidenceLinks as $link)
                <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                    <div class="flex items-center gap-2">
                        <span class="px-1.5 py-0.5 rounded bg-blue-900/40 text-blue-300">{{ $link->evidence_type }}</span>
                        <span class="text-gray-300 font-mono">{{ $link->evidence_id }}</span>
                        <span class="text-gray-500 ml-auto">{{ $link->created_at?->format('Y-m-d H:i') ?? '—' }}</span>
                    </div>
                    @if($link->rationale)
                    <div class="text-gray-400 mt-1 pl-1">{{ $link->rationale }}</div>
                    @endif
                </div>
                @empty
                <p class="text-xs text-gray-500">No evidence links for this investigation.</p>
                @endforelse
            </div>
        </div>

        <a href="{{ route('investigation.advanced.workspace') }}" class="text-xs text-gray-400 hover:text-gray-300">← Back to Workspace</a>

    </div>
</x-app-layout>
