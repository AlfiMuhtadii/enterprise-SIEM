<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Adversarial Replay Console</h2>
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
                    <th class="text-left py-1">Run ID</th><th class="text-left py-1">Scenario</th><th class="text-left py-1">Tactic</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">Detected</th><th class="text-left py-1">Confidence</th><th class="text-left py-1">Matched Rules</th><th class="text-left py-1">At</th>
                </tr></thead>
                <tbody>
                @foreach($runs as $r)
                <tr class="border-b border-slate-800">
                    <td class="py-1 font-mono">{{ $r->run_id }}</td>
                    <td class="py-1">{{ $r->scenario_name }}</td>
                    <td class="py-1">{{ $r->attack_tactic ?? '—' }}</td>
                    <td class="py-1"><span class="px-1.5 py-0.5 rounded text-xs {{ $r->verdict === 'pass' ? 'bg-green-800 text-green-200' : ($r->verdict === 'partial' ? 'bg-yellow-800 text-yellow-200' : 'bg-red-800 text-red-200') }}">{{ $r->verdict }}</span></td>
                    <td class="py-1">{{ $r->detected ? 'Yes' : 'No' }}</td>
                    <td class="py-1">{{ number_format($r->detection_confidence, 3) }}</td>
                    <td class="py-1">{{ $r->matched_rules }}</td>
                    <td class="py-1">{{ $r->created_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </div>
</x-app-layout>
