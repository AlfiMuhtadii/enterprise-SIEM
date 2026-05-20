<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Attack Timeline</h2>
        <p class="text-xs text-amber-400/80 mt-1">Reconstructed attack timeline for investigation {{ $investigationId }}. Advisory-only.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> Timeline reconstruction is read-only. Events are ordered by observed time. No enforcement actions are triggered.
        </div>

        <div class="grid grid-cols-2 gap-4 text-xs">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Events by MITRE Tactic ({{ $events->count() }} total)</h3>
                @foreach($byTactic as $tactic => $tacticEvents)
                <div class="mb-2">
                    <div class="font-medium text-purple-300 uppercase tracking-wide">{{ $tactic ?? 'unknown' }}</div>
                    @foreach($tacticEvents as $ev)
                    <div class="pl-3 py-1 border-l border-purple-700/40 mt-1">
                        <div class="text-gray-300">{{ Str::limit($ev->description, 80) }}</div>
                        <div class="text-gray-500 mt-0.5">
                            {{ $ev->mitre_technique ?? '' }}
                            · conf {{ number_format($ev->confidence, 2) }}
                            · {{ $ev->occurred_at?->format('Y-m-d H:i') ?? '—' }}
                        </div>
                    </div>
                    @endforeach
                </div>
                @endforeach
                @if($events->isEmpty())
                <p class="text-gray-500">No timeline events yet.</p>
                @endif
            </div>

            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Chronological View</h3>
                <div class="space-y-2 max-h-96 overflow-y-auto">
                    @forelse($events->sortBy('occurred_at') as $ev)
                    <div class="flex gap-3 py-1 border-b border-gray-800 last:border-0">
                        <span class="text-gray-500 shrink-0">{{ $ev->occurred_at?->format('H:i:s') ?? '—' }}</span>
                        <div>
                            <span class="text-xs px-1.5 py-0.5 rounded bg-purple-900/40 text-purple-300">{{ $ev->event_category }}</span>
                            <span class="text-gray-300 ml-1">{{ Str::limit($ev->description, 60) }}</span>
                        </div>
                    </div>
                    @empty
                    <p class="text-gray-500">No events.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <a href="{{ route('investigation.advanced.workspace') }}" class="text-xs text-gray-400 hover:text-gray-300">← Back to Workspace</a>

    </div>
</x-app-layout>
