<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Investigation Collaboration Timeline</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Analyst-driven collaborative workflows only. No autonomous SOC operations.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        <form method="GET" class="flex gap-3">
            <input name="investigation_id" value="{{ $investigationId }}" placeholder="Investigation ID (INV-...)"
                   class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-72">
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Load</button>
        </form>

        @if($currentState)
        <div class="flex gap-3 items-center">
            <span class="text-xs text-gray-400">Current Workflow State:</span>
            <span class="px-2 py-0.5 rounded bg-cyan-700/40 text-cyan-200 text-xs font-medium">{{ str_replace('_', ' ', $currentState) }}</span>
        </div>
        @endif

        @if($investigationId && $timeline->isNotEmpty())
        <div class="space-y-2">
            @foreach($timeline as $item)
            <div class="flex gap-4 items-start">
                <div class="text-xs text-gray-500 w-28 shrink-0 pt-0.5">{{ $item['at']?->format('M d H:i') }}</div>
                <div class="flex-1 rounded border border-cyan-400/10 bg-gray-900/20 px-3 py-2">
                    <span class="text-xs px-1.5 py-0.5 rounded {{ $item['type'] === 'escalation' ? 'bg-red-900/40 text-red-300' : ($item['type'] === 'collaborator' ? 'bg-cyan-900/40 text-cyan-300' : 'bg-gray-700 text-gray-400') }}">
                        {{ $item['type'] }}
                    </span>
                    @if($item['type'] === 'collaborator')
                    <span class="text-xs text-gray-300 ml-2">{{ $item['data']->analyst?->name ?? 'Analyst #'.$item['data']->analyst_id }} ({{ $item['data']->role }})</span>
                    @elseif($item['type'] === 'escalation')
                    <span class="text-xs text-gray-300 ml-2">{{ $item['data']->event_type }} — {{ $item['data']->workflow_state }}</span>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
        @elseif($investigationId)
        <p class="text-xs text-gray-500">No collaboration activity found for "{{ $investigationId }}".</p>
        @endif
    </div>
</x-app-layout>
