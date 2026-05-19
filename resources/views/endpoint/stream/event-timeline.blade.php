<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Endpoint Event Timeline: {{ $agent->hostname }}</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Near-real-time advisory telemetry. Not kernel-level EDR.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        @if(!empty($gaps))
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Sequence gaps detected ({{ count($gaps) }}): events may have been dropped or delivered out of order.
            <ul class="mt-1 space-y-0.5 text-xs">
                @foreach($gaps as $gap)
                    <li>Gap: sequence {{ $gap['from'] }} → {{ $gap['to'] }} ({{ $gap['missing_count'] }} missing)</li>
                @endforeach
            </ul>
        </div>
        @endif

        @if($events->isEmpty())
            <p class="text-sm text-gray-500">No stream events for this agent.</p>
        @else
        <div class="space-y-1.5">
            @foreach($events as $ev)
            <div class="flex gap-4 items-start">
                <div class="text-xs text-gray-500 w-28 shrink-0 pt-0.5 font-mono">{{ $ev->occurred_at?->format('H:i:s') }}</div>
                <div class="text-xs text-gray-600 w-16 shrink-0 pt-0.5 font-mono">#{{ $ev->sequence_id }}</div>
                <div class="flex-1 rounded border border-cyan-400/10 bg-gray-900/30 px-3 py-2">
                    <div class="flex items-center gap-2 text-xs">
                        <span class="font-medium text-cyan-300">{{ str_replace('_', ' ', $ev->event_type) }}</span>
                        @if($ev->process_name)
                            <span class="text-gray-400 font-mono">{{ $ev->process_name }}</span>
                            @if($ev->process_pid)<span class="text-gray-600">({{ $ev->process_pid }})</span>@endif
                        @endif
                        @if($ev->connection_dest)
                            <span class="text-blue-400 text-xs ml-auto">→ {{ $ev->connection_dest }}:{{ $ev->connection_port }}</span>
                        @endif
                    </div>
                    @if($ev->shell_hint)
                        <div class="mt-0.5 text-xs text-red-400/80 font-mono">{{ $ev->shell_hint }}</div>
                    @endif
                    @if($ev->persistence_key)
                        <div class="mt-0.5 text-xs text-amber-400/80 font-mono">{{ $ev->persistence_type }}: {{ $ev->persistence_key }}</div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
        @endif

        <a href="{{ route('endpoint.stream.health') }}" class="text-sm text-cyan-400 hover:underline">← Stream Health</a>
    </div>
</x-app-layout>
