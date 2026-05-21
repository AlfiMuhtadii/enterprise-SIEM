<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Distributed Reliability Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Reliability controls are operational safeguards only. No autonomous remediation, destructive replay, or hidden data deletion is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Safeguard Notice:</strong> Reliability controls are operational safeguards only. No autonomous remediation, destructive replay, or hidden data deletion is executed.
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('reliability.worker-health') }}" class="px-3 py-1.5 text-xs rounded bg-cyan-900/30 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/50">Worker Health</a>
            <a href="{{ route('reliability.lag-monitor') }}" class="px-3 py-1.5 text-xs rounded bg-blue-900/30 text-blue-300 border border-blue-700/40 hover:bg-blue-900/50">Consumer Lag</a>
            <a href="{{ route('reliability.throttle-console') }}" class="px-3 py-1.5 text-xs rounded bg-orange-900/30 text-orange-300 border border-orange-700/40 hover:bg-orange-900/50">Replay Throttle</a>
            <a href="{{ route('reliability.idempotency') }}" class="px-3 py-1.5 text-xs rounded bg-green-900/30 text-green-300 border border-green-700/40 hover:bg-green-900/50">Idempotency</a>
            <a href="{{ route('reliability.duplicate-reports') }}" class="px-3 py-1.5 text-xs rounded bg-red-900/30 text-red-300 border border-red-700/40 hover:bg-red-900/50">Duplicate Events</a>
            <a href="{{ route('reliability.storage-pressure') }}" class="px-3 py-1.5 text-xs rounded bg-purple-900/30 text-purple-300 border border-purple-700/40 hover:bg-purple-900/50">Storage Pressure</a>
            <a href="{{ route('reliability.degraded-mode') }}" class="px-3 py-1.5 text-xs rounded bg-yellow-900/30 text-yellow-300 border border-yellow-700/40 hover:bg-yellow-900/50">Degraded Mode</a>
            <a href="{{ route('reliability.recovery-validation') }}" class="px-3 py-1.5 text-xs rounded bg-gray-800 text-gray-300 border border-gray-600 hover:bg-gray-700">Recovery Validation</a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach([
                ['Total Workers', $stats['total_workers'], 'text-cyan-300'],
                ['Healthy Workers', $stats['healthy_workers'], 'text-green-300'],
                ['Stale Workers', $stats['stale_workers'], 'text-yellow-400'],
                ['Stalled Workers', $stats['stalled_workers'], 'text-red-400'],
                ['Critical Lag', $stats['critical_lag_count'], 'text-red-300'],
                ['Throttled Topics', $stats['throttled_topics'], 'text-orange-300'],
                ['Duplicate Events', $stats['duplicate_events'], 'text-red-300'],
                ['Degraded Entries', $stats['degraded_mode_entries'], 'text-yellow-300'],
            ] as [$label, $value, $color])
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $value }}</div>
            </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-xs">
            <div class="rounded border border-green-700/30 bg-green-900/10 px-4 py-3">
                <div class="text-green-300 font-medium">Autonomous Remediation</div>
                <div class="text-green-200 mt-1">{{ $stats['autonomous_remediation'] ? 'ENABLED (WARNING)' : 'DISABLED — safeguards only' }}</div>
            </div>
            <div class="rounded border border-green-700/30 bg-green-900/10 px-4 py-3">
                <div class="text-green-300 font-medium">Recovery Runs Passed</div>
                <div class="text-green-200 mt-1">{{ $stats['recovery_runs_passed'] }}</div>
            </div>
            <div class="rounded border border-green-700/30 bg-green-900/10 px-4 py-3">
                <div class="text-green-300 font-medium">Exhausted Retry Budgets</div>
                <div class="text-green-200 mt-1">{{ $stats['exhausted_topics'] }}</div>
            </div>
        </div>

    </div>
</x-app-layout>
