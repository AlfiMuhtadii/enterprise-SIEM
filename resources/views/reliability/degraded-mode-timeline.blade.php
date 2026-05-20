<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Degraded Mode Timeline</h2>
        <p class="text-xs text-amber-400/80 mt-1">Reliability controls are operational safeguards only. No autonomous remediation, destructive replay, or hidden data deletion is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Notice:</strong> Degraded mode transitions are explicit and audit-visible. No hidden fallback behavior or silent downgrade occurs.
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Services ({{ $byService->count() }})</h3>
                @foreach($byService as $service => $svcEvents)
                <div class="mb-2">
                    <div class="text-xs font-medium text-cyan-300">{{ $service }}</div>
                    <div class="text-xs text-gray-500">{{ $svcEvents->count() }} events</div>
                </div>
                @endforeach
                @if($events->isEmpty())<p class="text-xs text-gray-500">No degraded mode events.</p>@endif
            </div>

            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Recent Events</h3>
                <div class="space-y-1 max-h-64 overflow-y-auto">
                    @forelse($events->take(50) as $ev)
                    <div class="py-1 border-b border-gray-800 last:border-0 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="text-gray-500">{{ $ev->created_at?->format('H:i:s') ?? '—' }}</span>
                            <span class="text-cyan-300">{{ $ev->service_name }}</span>
                            <span class="px-1.5 py-0.5 rounded
                                @if($ev->event_type === 'entered') bg-red-900/40 text-red-300
                                @elseif($ev->event_type === 'cleared') bg-green-900/40 text-green-300
                                @else bg-yellow-900/40 text-yellow-300 @endif">{{ $ev->event_type }}</span>
                        </div>
                        <div class="text-gray-400 mt-0.5">{{ $ev->degraded_reason }}</div>
                    </div>
                    @empty
                    <p class="text-xs text-gray-500">No events.</p>
                    @endforelse
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
