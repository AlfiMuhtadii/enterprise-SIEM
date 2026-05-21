<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">External Case Links</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory-only. No autonomous ticket closure. All links are append-only records.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            External case links reference investigation ↔ ticket system relationships.
            Auto-close is always false. Ticket closure requires explicit analyst action in the ticketing system.
        </div>
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Investigation ↔ Ticket Links</h3>
            <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead><tr class="text-gray-400 uppercase text-left border-b border-gray-700">
                    <th class="pb-2 pr-4">Investigation</th>
                    <th class="pb-2 pr-4">External Ticket</th>
                    <th class="pb-2 pr-4">Direction</th>
                    <th class="pb-2 pr-4">External Status</th>
                    <th class="pb-2 pr-4">Advisory Only</th>
                    <th class="pb-2 pr-4">Auto Closed</th>
                    <th class="pb-2">Created</th>
                </tr></thead>
                <tbody>
                @forelse($links as $link)
                <tr class="border-b border-gray-800 hover:bg-gray-800/30">
                    <td class="py-1.5 pr-4 font-mono text-blue-300 text-xs">{{ Str::limit($link->investigation_id, 16) }}</td>
                    <td class="py-1.5 pr-4 text-gray-200">{{ $link->external_ticket_id }}</td>
                    <td class="py-1.5 pr-4 text-gray-400">{{ $link->link_direction }}</td>
                    <td class="py-1.5 pr-4 text-gray-400">{{ $link->external_status ?? '—' }}</td>
                    <td class="py-1.5 pr-4 text-green-400">Yes</td>
                    <td class="py-1.5 pr-4 text-gray-600">Never</td>
                    <td class="py-1.5 text-gray-500">{{ $link->created_at?->diffForHumans() }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="py-4 text-center text-gray-500">No case links created.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>
</x-app-layout>
