<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Attack Stage Timeline</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory-only. Non-destructive. Retrospective investigation tool.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Attack stage timelines are advisory-only and non-destructive. No automated response or containment actions are taken.
        </div>

        <form method="GET" class="flex gap-3 items-center">
            <select name="stage" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                <option value="">— Select Stage —</option>
                @foreach($stages as $s)
                    <option value="{{ $s }}" @selected($s === $stage)>{{ str_replace('_', ' ', $s) }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm hover:bg-cyan-700/60">Search</button>
        </form>

        @if($stage && !empty($result['timelines']))
        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Stage: {{ str_replace('_', ' ', $stage) }} — {{ count($result['timelines']) }} entries</h3>
            <div class="space-y-2">
                @foreach($result['timelines'] as $t)
                <div class="p-2 rounded bg-gray-800/30 text-xs text-gray-300 flex gap-4">
                    <span class="font-mono text-gray-500">{{ $t->timeline_id }}</span>
                    <span>{{ number_format($t->stage_confidence * 100) }}% confidence</span>
                    <span class="text-gray-500">{{ $t->first_seen_at }}</span>
                </div>
                @endforeach
            </div>
        </div>
        @elseif($stage)
        <p class="text-sm text-gray-500">No timeline entries found for stage "{{ $stage }}".</p>
        @endif

        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Recent Timeline Entries</h3>
            @if($recentTimelines->isEmpty())
                <p class="text-sm text-gray-500">No attack stage timelines recorded yet.</p>
            @else
            <table class="w-full text-xs text-left text-gray-300">
                <thead class="text-gray-400 uppercase border-b border-gray-700">
                    <tr>
                        <th class="py-2 pr-3">ID</th><th class="py-2 pr-3">Stage</th>
                        <th class="py-2 pr-3">Order</th><th class="py-2 pr-3">Confidence</th><th class="py-2">Created</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentTimelines as $t)
                    <tr class="border-b border-gray-800 hover:bg-gray-800/20">
                        <td class="py-1.5 pr-3 font-mono">{{ $t->timeline_id }}</td>
                        <td class="py-1.5 pr-3">{{ str_replace('_', ' ', $t->stage) }}</td>
                        <td class="py-1.5 pr-3 text-center">{{ $t->stage_order }}</td>
                        <td class="py-1.5 pr-3">{{ number_format($t->stage_confidence * 100) }}%</td>
                        <td class="py-1.5 text-gray-500">{{ $t->created_at }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>
</x-app-layout>
