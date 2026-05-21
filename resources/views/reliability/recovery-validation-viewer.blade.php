<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Recovery Validation Viewer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Reliability controls are operational safeguards only. No autonomous remediation, destructive replay, or hidden data deletion is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Total Runs</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $runs->count() }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Pass Rate</div>
                <div class="text-2xl font-bold text-green-300">{{ number_format($passRate * 100, 1) }}%</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Failed Runs</div>
                <div class="text-2xl font-bold text-red-300">{{ $runs->where('status', 'failed')->count() }}</div>
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Recovery Runs ({{ $runs->count() }})</h3>
            @forelse($runs as $run)
            <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                <div class="flex items-center gap-2">
                    <span class="text-cyan-300 font-medium">{{ $run->scenario }}</span>
                    <span class="px-1.5 py-0.5 rounded
                        @if($run->status === 'passed') bg-green-900/40 text-green-300
                        @elseif($run->status === 'warning') bg-yellow-900/40 text-yellow-300
                        @elseif($run->status === 'failed') bg-red-900/40 text-red-300
                        @else bg-blue-900/40 text-blue-300 @endif">{{ $run->status }}</span>
                    <span class="text-gray-400">{{ $run->passed_checks }}/{{ $run->total_checks }} checks</span>
                </div>
                <div class="flex gap-3 text-gray-500 mt-0.5">
                    <span>trace: {{ $run->trace_propagation_ok ? '✓' : '✗' }}</span>
                    <span>idempotency: {{ $run->replay_idempotency_ok ? '✓' : '✗' }}</span>
                    <span>stale detect: {{ $run->worker_stale_detection_ok ? '✓' : '✗' }}</span>
                    <span class="ml-auto">{{ $run->completed_at?->format('Y-m-d H:i') ?? 'running' }}</span>
                </div>
                @if($run->failure_summary)
                <div class="text-red-300 mt-0.5">{{ $run->failure_summary }}</div>
                @endif
            </div>
            @empty
            <p class="text-xs text-gray-500">No recovery validation runs.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
