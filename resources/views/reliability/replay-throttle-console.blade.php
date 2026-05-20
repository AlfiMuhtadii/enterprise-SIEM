<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Replay Throttle Console</h2>
        <p class="text-xs text-amber-400/80 mt-1">Reliability controls are operational safeguards only. No autonomous remediation, destructive replay, or hidden data deletion is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Throttle Notice:</strong> Replay throttles bound retry behavior to prevent unbounded queue growth. No unsafe queue purge or destructive replay is executed.
        </div>

        @if($exhausted->count() > 0)
        <div class="rounded border border-red-500/40 bg-red-900/10 px-4 py-3 text-sm text-red-300">
            <strong>Retry Budget Exhausted:</strong> {{ $exhausted->count() }} consumer group(s) have exhausted their retry budget. Operator intervention required.
        </div>
        @endif

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Throttle States ({{ $throttles->count() }})</h3>
            @forelse($throttles as $t)
            <div class="flex items-center justify-between py-2 border-b border-gray-800 last:border-0 text-xs">
                <div>
                    <span class="text-cyan-300">{{ $t->consumer_group }}</span>
                    @if($t->topic)<span class="ml-2 text-gray-400">{{ $t->topic }}</span>@endif
                    <span class="ml-2 px-1.5 py-0.5 rounded
                        @if($t->state === 'exhausted') bg-red-900/40 text-red-300
                        @elseif($t->state === 'throttled') bg-yellow-900/40 text-yellow-300
                        @else bg-green-900/40 text-green-300 @endif">{{ $t->state }}</span>
                </div>
                <div class="text-gray-400">
                    retries: {{ $t->current_retry_count }}/{{ $t->retry_budget_limit }}
                    · max: {{ $t->max_events_per_second }}/s
                </div>
            </div>
            @empty
            <p class="text-xs text-gray-500">No throttle states configured.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
