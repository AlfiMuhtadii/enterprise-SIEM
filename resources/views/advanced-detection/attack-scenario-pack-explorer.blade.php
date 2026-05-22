<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Attack Scenario Pack Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Advanced detections are advisory-only, replay-safe, and evidence-linked. No autonomous enforcement or offensive action is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Advanced detections are advisory-only, replay-safe, and evidence-linked. No autonomous enforcement or offensive action is executed.
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Scenario Packs</h3>
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700">
                    <th class="text-left py-1">Pack ID</th><th class="text-left py-1">Name</th><th class="text-left py-1">Tactic</th><th class="text-left py-1">Difficulty</th><th class="text-left py-1">Active</th>
                </tr></thead>
                <tbody>
                @foreach($packs as $p)
                <tr class="border-b border-slate-800">
                    <td class="py-1 font-mono">{{ $p->pack_id }}</td>
                    <td class="py-1">{{ $p->name }}</td>
                    <td class="py-1">{{ $p->attack_tactic }}</td>
                    <td class="py-1">{{ $p->difficulty }}</td>
                    <td class="py-1">{{ $p->is_active ? 'Yes' : 'No' }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Replay Fixtures</h3>
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700">
                    <th class="text-left py-1">Fixture ID</th><th class="text-left py-1">Name</th><th class="text-left py-1">Tactic</th><th class="text-left py-1">Type</th><th class="text-left py-1">Active</th>
                </tr></thead>
                <tbody>
                @foreach($fixtures as $f)
                <tr class="border-b border-slate-800">
                    <td class="py-1 font-mono">{{ $f->fixture_id }}</td>
                    <td class="py-1">{{ $f->name }}</td>
                    <td class="py-1">{{ $f->attack_tactic }}</td>
                    <td class="py-1">{{ $f->fixture_type }}</td>
                    <td class="py-1">{{ $f->is_active ? 'Yes' : 'No' }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </div>
</x-app-layout>
