<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Privilege Escalation Timeline</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Endpoint telemetry is advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Privilege escalation indicators are advisory-only. No account suspension, process termination, or host isolation is performed.
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">Total Indicators (7d)</div>
                <div class="text-2xl font-bold text-orange-400">{{ $summary['total'] }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 mb-1">High Confidence</div>
                <div class="text-2xl font-bold text-red-400">{{ $summary['high_confidence'] }}</div>
            </div>
        </div>

        <div class="glass-card p-4 space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">By Escalation Type</h3>
            @forelse($summary['by_type'] as $row)
            <div class="flex justify-between text-xs text-gray-300 border-b border-gray-700/40 pb-1">
                <span class="font-mono text-orange-300">{{ $row->escalation_type }}</span>
                <span>{{ $row->count }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No escalation indicators recorded.</p>
            @endforelse
        </div>

        <div class="glass-card p-4 space-y-2">
            <h3 class="text-sm font-semibold text-orange-300">Timeline — High Confidence First</h3>
            @forelse($timeline as $esc)
            <div class="text-xs border-b border-gray-700/40 pb-2 space-y-0.5">
                <div class="flex justify-between">
                    <span class="font-mono text-orange-300">{{ $esc->process_name }}</span>
                    <span class="text-gray-500">conf: {{ number_format($esc->confidence, 2) }}</span>
                </div>
                <div class="flex justify-between text-gray-400">
                    <span>{{ $esc->escalation_type }}</span>
                    <span>{{ $esc->original_user }} → {{ $esc->escalated_user ?? 'root' }}</span>
                </div>
                <div class="text-gray-500">{{ $esc->occurred_at?->diffForHumans() }}</div>
            </div>
            @empty
            <p class="text-xs text-gray-500">No privilege escalation indicators in the last 7 days.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
