<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">ATT&CK Coverage Map</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Detection lifecycle actions are governed, replay-validated, and do not execute autonomous response.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> ATT&CK mapping is advisory metadata. No automatic promotion occurs based solely on coverage mapping.
        </div>

        <div class="grid grid-cols-2 gap-6">
            <div class="glass-card p-4 space-y-2">
                <h3 class="text-sm font-semibold text-purple-200">Coverage by Tactic</h3>
                @forelse($coverage as $row)
                <div class="flex justify-between text-xs text-gray-300 border-b border-gray-700/40 pb-1">
                    <span class="text-purple-300">{{ $row->tactic }}</span>
                    <span>{{ $row->technique_count }} techniques · {{ $row->rule_count }} rules</span>
                </div>
                @empty
                <p class="text-xs text-gray-500">No ATT&CK mappings in lifecycle system yet.</p>
                @endforelse
            </div>
            <div class="glass-card p-4 space-y-2">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-purple-200">Registry Coverage (from registry.v1.json)</h3>
                    <a href="{{ route('detection.lifecycle.attack-map.navigator-layer') }}"
                       class="rounded border border-cyan-200/20 bg-cyan-100/10 px-2 py-1 text-xs text-cyan-50 hover:bg-cyan-100/20">
                        Download Navigator layer.json
                    </a>
                </div>
                <p class="text-xs text-muted-ui">{{ count($mitre) }} technique(s) covered — showing first 15. Import the downloaded layer into <span class="mono-ui">mitre-attack.github.io/attack-navigator</span> for the full interactive matrix.</p>
                @forelse(array_slice($mitre, 0, 15) as $tech)
                <div class="flex justify-between text-xs text-gray-300 border-b border-gray-700/40 pb-1">
                    <span class="font-mono text-purple-300">{{ $tech['technique'] }}</span>
                    <span>{{ count($tech['rules']) }} rules</span>
                </div>
                @empty
                <p class="text-xs text-gray-500">No MITRE techniques in registry.</p>
                @endforelse
            </div>
        </div>

        <div class="space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">Enriched ATT&CK Mappings (lifecycle system)</h3>
            @forelse($mappings as $m)
            <div class="glass-card p-4 space-y-1 text-xs">
                <div class="flex justify-between">
                    <span class="font-mono text-purple-300">{{ $m->technique_id }}</span>
                    <span class="text-gray-500">conf: {{ number_format($m->confidence, 2) }}</span>
                </div>
                <div class="flex gap-3 text-gray-400">
                    <span>Rule: <span class="font-mono text-gray-300">{{ $m->rule_id }}</span></span>
                    <span>Tactic: {{ $m->tactic }}</span>
                    <span>Source: {{ $m->mapping_source }}</span>
                </div>
                <div class="text-gray-300">{{ $m->technique_name }}</div>
            </div>
            @empty
            <p class="text-xs text-gray-500">No lifecycle ATT&CK mappings yet. Registry mappings shown above.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
