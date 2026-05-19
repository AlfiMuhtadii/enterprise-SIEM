<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">DNS Investigation</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Network analytics are shadow-only and advisory. No blocking or containment is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-6xl mx-auto space-y-5">
        <form method="GET" class="flex gap-3">
            <input name="host_id" value="{{ $hostId }}" placeholder="Filter by host_id"
                   class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-60">
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Filter</button>
        </form>
        <table class="w-full text-xs text-left text-gray-300">
            <thead class="text-gray-400 uppercase border-b border-gray-700">
                <tr><th class="py-2 pr-3">Domain</th><th class="py-2 pr-3">Type</th><th class="py-2 pr-3">Response</th><th class="py-2 pr-3">Host</th><th class="py-2 pr-3">Entropy</th><th class="py-2">Time</th></tr>
            </thead>
            <tbody>
                @forelse($events as $e)
                <tr class="border-b border-gray-800 @if($e->is_nxdomain) bg-red-900/10 @endif">
                    <td class="py-1.5 pr-3 font-mono">{{ $e->queried_domain }}</td>
                    <td class="py-1.5 pr-3">{{ $e->query_type }}</td>
                    <td class="py-1.5 pr-3 @if($e->is_nxdomain) text-red-400 @endif">{{ $e->response_code }}</td>
                    <td class="py-1.5 pr-3 text-gray-400 font-mono text-xs">{{ $e->host_id }}</td>
                    <td class="py-1.5 pr-3 @if($e->domain_entropy > 3.5) text-amber-400 @endif">{{ $e->domain_entropy ? number_format($e->domain_entropy, 2) : '-' }}</td>
                    <td class="py-1.5 text-gray-500">{{ $e->occurred_at?->format('H:i:s') }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-3 text-gray-500">No DNS events.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
