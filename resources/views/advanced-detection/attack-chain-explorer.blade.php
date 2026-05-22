<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Attack Chain Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Advanced detections are advisory-only, replay-safe, and evidence-linked. No autonomous enforcement or offensive action is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Advanced detections are advisory-only, replay-safe, and evidence-linked. No autonomous enforcement or offensive action is executed.
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700">
                    <th class="text-left py-1">Graph ID</th><th class="text-left py-1">Chain Type</th><th class="text-left py-1">Hops</th><th class="text-left py-1">Confidence</th><th class="text-left py-1">Host</th><th class="text-left py-1">Actor</th><th class="text-left py-1">At</th>
                </tr></thead>
                <tbody>
                @foreach($graphs as $g)
                <tr class="border-b border-slate-800">
                    <td class="py-1 font-mono">{{ $g->graph_id }}</td>
                    <td class="py-1">{{ $g->chain_type }}</td>
                    <td class="py-1">{{ $g->hop_count }}</td>
                    <td class="py-1">{{ number_format($g->chain_confidence, 3) }}</td>
                    <td class="py-1">{{ $g->host_id ?? '—' }}</td>
                    <td class="py-1">{{ $g->actor ?? '—' }}</td>
                    <td class="py-1">{{ $g->created_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </div>
</x-app-layout>
