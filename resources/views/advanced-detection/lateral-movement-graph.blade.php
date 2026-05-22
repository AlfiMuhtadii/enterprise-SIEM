<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Lateral Movement Graph</h2>
        <p class="text-xs text-amber-400/80 mt-1">Advanced detections are advisory-only, replay-safe, and evidence-linked. No autonomous enforcement or offensive action is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Advanced detections are advisory-only, replay-safe, and evidence-linked. No autonomous enforcement or offensive action is executed.
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-red-300">{{ $runs->count() }}</div>
                <div class="text-xs text-slate-400 mt-1">Lateral Correlation Runs</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-orange-300">{{ $chains->count() }}</div>
                <div class="text-xs text-slate-400 mt-1">Lateral Chain Graphs</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-yellow-300">{{ $runs->where('propagation_detected', true)->count() }}</div>
                <div class="text-xs text-slate-400 mt-1">Propagation Detected</div>
            </div>
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700">
                    <th class="text-left py-1">Run ID</th><th class="text-left py-1">Hosts</th><th class="text-left py-1">Propagation</th><th class="text-left py-1">Confidence</th><th class="text-left py-1">At</th>
                </tr></thead>
                <tbody>
                @foreach($runs as $r)
                <tr class="border-b border-slate-800">
                    <td class="py-1 font-mono">{{ $r->run_id }}</td>
                    <td class="py-1">{{ $r->host_count }}</td>
                    <td class="py-1">{{ $r->propagation_detected ? 'Yes' : 'No' }}</td>
                    <td class="py-1">{{ number_format($r->correlation_confidence, 3) }}</td>
                    <td class="py-1">{{ $r->created_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </div>
</x-app-layout>
