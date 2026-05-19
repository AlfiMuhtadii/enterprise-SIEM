<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Network Behavioral Findings</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Network analytics are shadow-only and advisory. No blocking or containment is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-6xl mx-auto space-y-5">
        <form method="GET" class="flex gap-3">
            <select name="source" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                <option value="">All Sources</option>
                @foreach($sources as $s)
                <option value="{{ $s }}" @selected($s === $source)>{{ strtoupper($s) }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Filter</button>
        </form>
        <table class="w-full text-xs text-left text-gray-300">
            <thead class="text-gray-400 uppercase border-b border-gray-700">
                <tr><th class="py-2 pr-3">Finding Type</th><th class="py-2 pr-3">Source</th><th class="py-2 pr-3">Host</th><th class="py-2 pr-3">Target</th><th class="py-2 pr-3">Severity</th><th class="py-2">Time</th></tr>
            </thead>
            <tbody>
                @forelse($findings as $f)
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 pr-3">{{ str_replace('_', ' ', $f->finding_type) }}</td>
                    <td class="py-1.5 pr-3">
                        <span class="px-1.5 py-0.5 rounded text-xs {{ $f->source_domain === 'dns' ? 'bg-blue-900/40 text-blue-300' : ($f->source_domain === 'proxy' ? 'bg-purple-900/40 text-purple-300' : 'bg-orange-900/40 text-orange-300') }}">
                            {{ $f->source_domain }}
                        </span>
                    </td>
                    <td class="py-1.5 pr-3 font-mono text-xs text-gray-400">{{ $f->host_id }}</td>
                    <td class="py-1.5 pr-3 font-mono">{{ $f->target_domain ?: $f->target_ip }}</td>
                    <td class="py-1.5 pr-3">
                        <span class="{{ $f->severity === 'high' ? 'text-red-400' : ($f->severity === 'medium' ? 'text-amber-400' : 'text-gray-400') }}">
                            {{ $f->severity }}
                        </span>
                    </td>
                    <td class="py-1.5 text-gray-500">{{ $f->created_at?->format('H:i:s') }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-3 text-gray-500">No behavioral findings.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
