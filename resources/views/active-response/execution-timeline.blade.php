<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Execution Timeline: {{ $execution->execution_id }}</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Append-only audit trail. No mutations to history.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-4xl mx-auto space-y-5">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Controlled manual response only. No autonomous containment. All events are append-only.
        </div>

        <div class="space-y-2">
            @foreach($execution->events->sortBy('created_at') as $i => $ev)
            <div class="flex gap-4 items-start">
                <div class="text-xs text-gray-500 w-32 shrink-0 pt-0.5">{{ $ev->created_at?->format('H:i:s') }}</div>
                <div class="flex-1 rounded-lg border border-cyan-400/15 bg-gray-900/30 p-3">
                    <div class="flex items-center gap-3 text-sm">
                        <span class="font-medium text-cyan-300">{{ str_replace('_', ' ', $ev->event_type) }}</span>
                        @if($ev->from_state)
                        <span class="text-gray-500 text-xs">{{ $ev->from_state }}</span>
                        <span class="text-gray-600 text-xs">→</span>
                        <span class="text-gray-300 text-xs">{{ $ev->to_state }}</span>
                        @endif
                        @if($ev->actor_name)
                        <span class="text-gray-500 text-xs ml-auto">by {{ $ev->actor_name }}</span>
                        @endif
                    </div>
                    @if(!empty($ev->details))
                    <div class="mt-1 text-xs text-gray-500 font-mono">
                        {{ json_encode($ev->details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}
                    </div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>

        <a href="{{ route('active-response.show', $execution->execution_id) }}" class="text-sm text-cyan-400 hover:underline">← Back to Execution</a>
    </div>
</x-app-layout>
