<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">False Positive Reports</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Detection lifecycle actions are governed, replay-validated, and do not execute autonomous response.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> False-positive reports are advisory. Suppression is not automatic — it requires a separate operator-approved action.
        </div>

        <div class="glass-card p-4 space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">By Reason Type</h3>
            @forelse($byReason as $row)
            <div class="flex justify-between text-xs text-gray-300 border-b border-gray-700/40 pb-1">
                <span class="font-mono text-orange-300">{{ $row->reason_type }}</span>
                <span>{{ $row->count }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No FP reports yet.</p>
            @endforelse
        </div>

        <div class="space-y-2">
            @forelse($reports as $report)
            <div class="glass-card p-4 space-y-1 text-xs">
                <div class="flex justify-between">
                    <span class="font-mono text-orange-300">{{ $report->rule_id }}</span>
                    <span class="text-gray-500">{{ $report->created_at?->diffForHumans() }}</span>
                </div>
                <div class="flex gap-3 text-gray-400">
                    <span>{{ $report->reason_type }}</span>
                    <span>verdict: <span class="text-yellow-300">{{ $report->analyst_verdict }}</span></span>
                    @if($report->recommends_suppression)<span class="text-orange-400">recommends suppression</span>@endif
                </div>
                <div class="text-gray-400 truncate">{{ $report->reason_detail }}</div>
            </div>
            @empty
            <p class="text-xs text-gray-500 p-4">No false-positive reports.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
