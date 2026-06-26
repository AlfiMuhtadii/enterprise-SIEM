<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Detection Domain Promotion Readiness</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory-only. No rules are promoted. ACTIVE_ALLOWLIST remains empty. Go correlation scope stays at identity/cloud/SaaS.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Gate Enforcement:</strong> Endpoint, network, and threat-intel rules require a domain-specific 6h soak PASS before any promotion is possible. This view is read-only — it does not change any rule status.
        </div>

        {{-- Summary counts --}}
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-green-400">{{ $summary['active'] }}</div>
                <div class="text-xs text-gray-400 mt-1">Active</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-cyan-400">{{ $summary['shadow_ready'] }}</div>
                <div class="text-xs text-gray-400 mt-1">Shadow Ready</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-yellow-400">{{ $summary['shadow_needs_soak'] }}</div>
                <div class="text-xs text-gray-400 mt-1">Needs Soak</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-orange-400">{{ $summary['deferred'] }}</div>
                <div class="text-xs text-gray-400 mt-1">Deferred</div>
            </div>
            <div class="glass-card p-4 text-center">
                <div class="text-2xl font-bold text-gray-500">{{ $summary['out_of_scope'] }}</div>
                <div class="text-xs text-gray-400 mt-1">Out of Scope</div>
            </div>
        </div>

        {{-- Category legend --}}
        <div class="glass-card p-4 text-xs space-y-1 text-gray-300">
            <div><span class="text-green-400 font-mono">active</span> — staged_active; 6h identity/cloud/SaaS soak PASS 2026-05-14</div>
            <div><span class="text-cyan-400 font-mono">shadow_ready</span> — shadow in active-domain (identity/cloud/saas); domain soak infra exists; soak is the only remaining gate</div>
            <div><span class="text-yellow-400 font-mono">shadow_needs_soak</span> — shadow endpoint behavioral; agent telemetry flowing; endpoint domain 6h soak not yet scheduled</div>
            <div><span class="text-orange-400 font-mono">deferred</span> — infrastructure prerequisite missing (network tap / live IOC feed / multi-domain active data)</div>
            <div><span class="text-gray-500 font-mono">out_of_scope</span> — intentional architectural boundary; will not be promoted under current scope</div>
        </div>

        {{-- Per-category rule tables --}}
        @foreach([
            ['key'=>'active',            'label'=>'Active (staged_active)',          'color'=>'text-green-400',  'border'=>'border-green-400/30'],
            ['key'=>'shadow_ready',      'label'=>'Shadow Ready (soak only)',        'color'=>'text-cyan-400',   'border'=>'border-cyan-400/30'],
            ['key'=>'shadow_needs_soak', 'label'=>'Shadow — Needs Domain Soak',      'color'=>'text-yellow-400', 'border'=>'border-yellow-400/30'],
            ['key'=>'deferred',          'label'=>'Deferred (infra prerequisite)',   'color'=>'text-orange-400', 'border'=>'border-orange-400/30'],
            ['key'=>'out_of_scope',      'label'=>'Out of Scope',                    'color'=>'text-gray-500',   'border'=>'border-gray-600/30'],
        ] as $cat)
        @if($byReadiness[$cat['key']]->count() > 0)
        <div class="glass-card p-4 space-y-3">
            <h3 class="font-semibold {{ $cat['color'] }}">{{ $cat['label'] }} <span class="text-gray-500 text-xs">({{ $byReadiness[$cat['key']]->count() }})</span></h3>
            <div class="overflow-x-auto">
                <table class="w-full text-xs border-collapse">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-700">
                            <th class="py-1 pr-4">Rule ID</th>
                            <th class="py-1 pr-4">Domain</th>
                            <th class="py-1 pr-4">Confidence</th>
                            <th class="py-1">Gate Remaining</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($byReadiness[$cat['key']] as $rule)
                        <tr class="border-b border-gray-800/50 hover:bg-gray-800/20">
                            <td class="py-1 pr-4 font-mono {{ $cat['color'] }}">{{ $rule['rule_id'] }}</td>
                            <td class="py-1 pr-4 text-gray-300">{{ $rule['domain'] }}</td>
                            <td class="py-1 pr-4 text-gray-400">{{ $rule['confidence'] !== null ? number_format($rule['confidence'], 2) : '—' }}</td>
                            <td class="py-1 text-gray-500 font-mono">{{ $rule['gate_remaining'] ?? '—' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
        @endforeach

    </div>
</x-app-layout>
