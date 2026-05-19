<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Cross-Domain Trace Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory-only. Retrospective trace stitching across domains.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Cross-domain trace exploration is advisory-only and non-destructive.
            Trace stitching correlates investigation context only.
        </div>

        <form method="GET" class="flex gap-3 items-center">
            <input type="text" name="trace_id" value="{{ $traceId }}" placeholder="trace-xxxxxxxx-xxxx-..."
                   class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-80 font-mono" />
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Stitch</button>
        </form>

        @if($traceId && !empty($result) && !isset($result['error']))
        <div class="space-y-4">
            @foreach(['alerts' => 'Security Alerts', 'incidents' => 'Incidents', 'findings' => 'Endpoint Findings', 'correlations' => 'Cross-Domain Correlations', 'hunt_results' => 'Hunt Results'] as $key => $label)
            @if(!empty($result[$key]))
            <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-cyan-200 mb-2">{{ $label }} ({{ count($result[$key]) }})</h3>
                <div class="space-y-1">
                    @foreach($result[$key] as $item)
                    <div class="text-xs p-1.5 rounded bg-gray-800/30 text-gray-300 font-mono">
                        {{ json_encode((array)$item, JSON_UNESCAPED_SLASHES) }}
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
            @endforeach

            <p class="text-xs text-gray-500 text-center">{{ $result['advisory_note'] ?? '' }}</p>
        </div>
        @elseif($traceId && isset($result['error']))
        <p class="text-sm text-red-400">{{ $result['error'] }}</p>
        @elseif($traceId)
        <p class="text-sm text-gray-500">No records found for trace "{{ $traceId }}".</p>
        @endif
    </div>
</x-app-layout>
