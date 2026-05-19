<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Firewall Flow Investigation</h2>
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
                <tr><th class="py-2 pr-3">Src IP</th><th class="py-2 pr-3">Dest IP</th><th class="py-2 pr-3">Port</th><th class="py-2 pr-3">Proto</th><th class="py-2 pr-3">Action</th><th class="py-2">Time</th></tr>
            </thead>
            <tbody>
                @forelse($events as $e)
                <tr class="border-b border-gray-800 @if($e->is_deny) bg-red-900/10 @endif">
                    <td class="py-1.5 pr-3 font-mono">{{ $e->source_ip }}</td>
                    <td class="py-1.5 pr-3 font-mono">{{ $e->destination_ip }}</td>
                    <td class="py-1.5 pr-3">{{ $e->destination_port }}</td>
                    <td class="py-1.5 pr-3">{{ $e->protocol }}</td>
                    <td class="py-1.5 pr-3">
                        <span class="px-1.5 py-0.5 rounded {{ $e->is_deny ? 'bg-red-900/40 text-red-300' : 'bg-green-900/40 text-green-300' }}">
                            {{ $e->action }}
                        </span>
                    </td>
                    <td class="py-1.5 text-gray-500">{{ $e->occurred_at?->format('H:i:s') }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-3 text-gray-500">No firewall events.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
