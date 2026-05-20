<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">ATT&CK Investigation Map</h2>
        <p class="text-xs text-amber-400/80 mt-1">MITRE ATT&CK coverage derived from investigation timeline events. Advisory-only.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> ATT&CK mapping is derived from observed investigation timeline events only. No enforcement actions are triggered.
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Observed Techniques ({{ $techniques->count() }})</h3>
            <div class="space-y-2">
                @forelse($techniques as $t)
                <div class="flex items-center gap-3 py-2 border-b border-gray-800 last:border-0 text-xs">
                    <span class="font-mono text-purple-300 w-20 shrink-0">{{ $t->mitre_technique }}</span>
                    <span class="text-gray-400 w-32 shrink-0">{{ $t->mitre_tactic ?? '—' }}</span>
                    <div class="flex-1 bg-gray-800 rounded h-2">
                        <div class="bg-purple-600 h-2 rounded" style="width: {{ min(100, $t->count * 10) }}%"></div>
                    </div>
                    <span class="text-gray-300 w-8 text-right">{{ $t->count }}</span>
                </div>
                @empty
                <p class="text-xs text-gray-500">No ATT&CK techniques observed in investigation timeline events yet.</p>
                @endforelse
            </div>
        </div>

    </div>
</x-app-layout>
