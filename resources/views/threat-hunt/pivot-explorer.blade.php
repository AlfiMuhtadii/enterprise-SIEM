<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Behavioral Pivot Explorer</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Threat hunting is advisory-only and non-destructive.</p>
            </div>
            <a href="{{ route('threat-hunt.dashboard') }}" class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Dashboard</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="glass-card p-6">
                <form method="GET" action="{{ route('threat-hunt.pivot-explorer') }}" class="flex flex-wrap gap-3 items-end">
                    <div>
                        <label class="block text-xs text-cyan-400/70 mb-1">Pivot Type</label>
                        <select name="type" class="text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200">
                            @foreach (['host', 'process', 'persistence', 'trace'] as $pt)
                                <option value="{{ $pt }}" {{ $pivotType === $pt ? 'selected' : '' }}>{{ ucfirst($pt) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex-1 min-w-40">
                        <label class="block text-xs text-cyan-400/70 mb-1">Identifier</label>
                        <input type="text" name="id" value="{{ $pivotId }}"
                            class="w-full text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200 placeholder-cyan-400/40"
                            placeholder="e.g. host agent_id, process name, trace_id...">
                    </div>
                    <button type="submit" class="px-4 py-2 rounded bg-cyan-700/30 border border-cyan-400/30 text-cyan-200 hover:bg-cyan-700/50 text-sm">Pivot</button>
                </form>
            </div>

            @if ($result)
                @if (isset($result['error']))
                    <div class="glass-card p-4 border border-red-400/30 text-red-300 text-sm">{{ $result['error'] }}</div>
                @else
                    <div class="glass-card p-6">
                        <h3 class="text-sm font-semibold text-cyan-200 mb-3">Pivot Result: {{ $pivotType }} = {{ $pivotId }}</h3>
                        <pre class="text-xs bg-black/30 rounded p-3 text-cyan-300/80 overflow-x-auto max-h-[600px]">{{ json_encode($result, JSON_PRETTY_PRINT) }}</pre>
                    </div>
                @endif
            @endif

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">Advisory-only pivot explorer. Graph traversal depth is bounded (max {{ \App\Services\ThreatHuntingService::MAX_GRAPH_DEPTH }}). No entity mutations.</p>
            </div>
        </div>
    </div>
</x-app-layout>
