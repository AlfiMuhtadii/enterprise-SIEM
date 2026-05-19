<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Network Threat Hunting Pivots</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Network analytics are shadow-only and advisory. No blocking or containment is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            All pivots are read-only and advisory. No autonomous response. No blocking.
        </div>

        <form method="GET" class="flex gap-3 items-end flex-wrap glass-card p-4">
            <div>
                <label class="text-xs text-gray-400 block mb-1">Pivot Type</label>
                <select name="pivot_type" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                    @foreach([
                        'domain' => 'Domain → Hosts',
                        'ip'     => 'IP → Hosts',
                        'user'   => 'User → Domains',
                        'host'   => 'Host → Outbound Destinations',
                    ] as $val => $label)
                    <option value="{{ $val }}" @selected($val === $pivotType)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-400 block mb-1">Pivot Key</label>
                <input name="pivot_key" value="{{ $pivotKey }}" placeholder="e.g. evil.example.com"
                       class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-60">
            </div>
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Pivot</button>
        </form>

        @if($result)
        <div class="glass-card p-4 space-y-3">
            <div class="text-xs text-gray-400 uppercase font-semibold">Pivot Results</div>
            @foreach($result as $key => $value)
            @if(!is_bool($value) && $key !== 'advisory_only' && $key !== 'no_blocking' && $key !== 'no_autonomous_response')
            <div class="py-1 border-b border-gray-800">
                <div class="text-xs text-gray-500 mb-0.5">{{ str_replace('_', ' ', $key) }}</div>
                @if(is_array($value))
                <div class="flex flex-wrap gap-1">
                    @foreach($value as $item)
                    <span class="font-mono text-xs bg-gray-800 px-1.5 py-0.5 rounded text-cyan-300">{{ $item }}</span>
                    @endforeach
                </div>
                @else
                <div class="font-mono text-xs text-cyan-200">{{ $value }}</div>
                @endif
            </div>
            @endif
            @endforeach
            <div class="text-xs text-amber-400/70 mt-2">Advisory-only. No autonomous response. No blocking.</div>
        </div>
        @elseif($pivotKey)
        <p class="text-sm text-gray-500">No results found for pivot "{{ $pivotKey }}".</p>
        @endif
    </div>
</x-app-layout>
