<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Detection Engineering Lifecycle</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Detection lifecycle actions are governed, replay-validated, and do not execute autonomous response.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Detection lifecycle actions are governed, replay-validated, and do not execute autonomous response. No autonomous promotion is performed.
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('detection.lifecycle.versions', ['ruleId' => 'IDENTITY_MFA_FAILURE_BURST']) }}" class="px-3 py-1.5 text-xs rounded bg-cyan-900/30 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/50">Version History</a>
            <a href="{{ route('detection.lifecycle.replay-packs') }}" class="px-3 py-1.5 text-xs rounded bg-blue-900/30 text-blue-300 border border-blue-700/40 hover:bg-blue-900/50">Replay Packs</a>
            <a href="{{ route('detection.lifecycle.replay-results') }}" class="px-3 py-1.5 text-xs rounded bg-green-900/30 text-green-300 border border-green-700/40 hover:bg-green-900/50">Replay Results</a>
            <a href="{{ route('detection.lifecycle.false-positives') }}" class="px-3 py-1.5 text-xs rounded bg-red-900/30 text-red-300 border border-red-700/40 hover:bg-red-900/50">False Positives</a>
            <a href="{{ route('detection.lifecycle.suppressions') }}" class="px-3 py-1.5 text-xs rounded bg-orange-900/30 text-orange-300 border border-orange-700/40 hover:bg-orange-900/50">Suppressions</a>
            <a href="{{ route('detection.lifecycle.attack-map') }}" class="px-3 py-1.5 text-xs rounded bg-purple-900/30 text-purple-300 border border-purple-700/40 hover:bg-purple-900/50">ATT&CK Map</a>
            <a href="{{ route('detection.lifecycle.promotions') }}" class="px-3 py-1.5 text-xs rounded bg-yellow-900/30 text-yellow-300 border border-yellow-700/40 hover:bg-yellow-900/50">Promotions</a>
            <a href="{{ route('detection.lifecycle.quality') }}" class="px-3 py-1.5 text-xs rounded bg-gray-800 text-gray-300 border border-gray-600 hover:bg-gray-700">Quality</a>
        </div>

        <div class="grid grid-cols-4 gap-4">
            @foreach([
                ['Rule Versions', $stats['total_versions'], 'text-cyan-300'],
                ['Replay Packs', $stats['total_replay_packs'], 'text-blue-300'],
                ['FP Reports (7d)', $stats['fp_reports_7d'], 'text-red-300'],
                ['Active Suppressions', $stats['active_suppressions'], 'text-orange-300'],
                ['Expiring Suppressions (7d)', $stats['expiring_suppressions'], 'text-yellow-400'],
                ['Pending Promotions', $stats['pending_promotions'], 'text-yellow-300'],
                ['ATT&CK Mappings', $stats['attack_mappings'], 'text-purple-300'],
                ['Replay Pass Rate', number_format($stats['replay_pass_rate'] * 100, 1).'%', 'text-green-300'],
            ] as [$label, $val, $color])
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $val }}</div>
            </div>
            @endforeach
        </div>

        <div class="glass-card p-4 text-xs text-gray-400">
            <p>autonomous_promotion: <span class="text-green-400">false</span> &nbsp;|&nbsp; advisory_only: <span class="text-green-400">true</span> &nbsp;|&nbsp; ACTIVE_ALLOWLIST: <span class="text-green-400">empty (intentional)</span></p>
        </div>

    </div>
</x-app-layout>
