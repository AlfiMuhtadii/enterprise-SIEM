<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">SOAR Orchestration Audit Timeline</h2>
        <p class="text-xs text-amber-400/80 mt-1">SOAR workflows are simulation-first and approval-gated. No autonomous enforcement or destructive response is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-2 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Events by Type ({{ $events->count() }} total)</h3>
                @foreach($byType as $type => $typeEvents)
                <div class="flex items-center justify-between py-1 border-b border-gray-800 last:border-0 text-xs">
                    <span class="text-purple-300">{{ $type }}</span>
                    <span class="text-gray-300">{{ $typeEvents->count() }}</span>
                </div>
                @endforeach
                @if($events->isEmpty())
                <p class="text-xs text-gray-500">No audit events yet.</p>
                @endif
            </div>

            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Recent Events</h3>
                <div class="space-y-1 max-h-64 overflow-y-auto">
                    @forelse($events->take(50) as $ev)
                    <div class="flex items-start gap-2 py-1 border-b border-gray-800 last:border-0 text-xs">
                        <span class="text-gray-500 shrink-0 w-16">{{ $ev->created_at?->format('H:i:s') ?? '—' }}</span>
                        <div>
                            <span class="text-purple-300">{{ $ev->event_type }}</span>
                            @if($ev->from_status && $ev->to_status)
                            <span class="text-gray-500 ml-1">{{ $ev->from_status }} → {{ $ev->to_status }}</span>
                            @endif
                            <div class="text-gray-400 mt-0.5">{{ Str::limit($ev->description, 70) }}</div>
                        </div>
                    </div>
                    @empty
                    <p class="text-xs text-gray-500">No audit events.</p>
                    @endforelse
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
