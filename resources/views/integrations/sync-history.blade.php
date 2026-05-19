<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Sync History</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Append-only audit log of all integration sync runs. Dry-run by default.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            All sync events are advisory_only=true. Dry-run is the default unless explicitly enabled.
            Records are append-only — no sync event is ever deleted or mutated.
        </div>
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Integration Sync Audit Log</h3>
            <table class="w-full text-xs">
                <thead><tr class="text-gray-400 uppercase text-left border-b border-gray-700">
                    <th class="pb-2 pr-4">Started</th>
                    <th class="pb-2 pr-4">Integration</th>
                    <th class="pb-2 pr-4">Type</th>
                    <th class="pb-2 pr-4">Direction</th>
                    <th class="pb-2 pr-4">Status</th>
                    <th class="pb-2 pr-4">Processed</th>
                    <th class="pb-2 pr-4">Failed</th>
                    <th class="pb-2 pr-4">Dry Run</th>
                    <th class="pb-2">Advisory</th>
                </tr></thead>
                <tbody>
                @forelse($syncs as $sync)
                <tr class="border-b border-gray-800 hover:bg-gray-800/30">
                    <td class="py-1.5 pr-4 text-gray-500">{{ $sync->started_at?->format('M d H:i') }}</td>
                    <td class="py-1.5 pr-4 text-gray-300">{{ $sync->integration?->name ?? '—' }}</td>
                    <td class="py-1.5 pr-4 text-blue-300">{{ $sync->sync_type }}</td>
                    <td class="py-1.5 pr-4 text-gray-400">{{ $sync->direction }}</td>
                    <td class="py-1.5 pr-4">
                        <span class="px-2 py-0.5 rounded text-xs {{ $sync->status === 'completed' ? 'bg-green-900/40 text-green-300' : ($sync->status === 'failed' ? 'bg-red-900/40 text-red-300' : 'bg-gray-700 text-gray-400') }}">
                            {{ $sync->status }}
                        </span>
                    </td>
                    <td class="py-1.5 pr-4 text-gray-300">{{ $sync->records_processed }}</td>
                    <td class="py-1.5 pr-4 text-{{ $sync->records_failed > 0 ? 'red' : 'gray' }}-400">
                        {{ $sync->records_failed }}
                    </td>
                    <td class="py-1.5 pr-4 text-amber-300">{{ $sync->dry_run ? 'Yes' : 'No' }}</td>
                    <td class="py-1.5 text-green-400">Yes</td>
                </tr>
                @empty
                <tr><td colspan="9" class="py-4 text-center text-gray-500">No sync events recorded.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
