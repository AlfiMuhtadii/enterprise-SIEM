<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Integration Registry</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory-only. Integrations start inactive. No autonomous actions on activation.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Registered Integrations</h3>
            <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead><tr class="text-gray-400 uppercase text-left border-b border-gray-700">
                    <th class="pb-2 pr-4">Name</th>
                    <th class="pb-2 pr-4">Type</th>
                    <th class="pb-2 pr-4">Provider</th>
                    <th class="pb-2 pr-4">Status</th>
                    <th class="pb-2 pr-4">Inbound</th>
                    <th class="pb-2 pr-4">Outbound</th>
                    <th class="pb-2">Last Sync</th>
                </tr></thead>
                <tbody>
                @forelse($integrations as $int)
                <tr class="border-b border-gray-800 hover:bg-gray-800/30">
                    <td class="py-2 pr-4 text-gray-200">{{ $int->name }}</td>
                    <td class="py-2 pr-4 text-blue-300">{{ $int->integration_type }}</td>
                    <td class="py-2 pr-4 text-gray-400">{{ $int->provider }}</td>
                    <td class="py-2 pr-4">
                        <span class="px-2 py-0.5 rounded text-xs {{ $int->status === 'active' ? 'bg-green-900/40 text-green-300' : 'bg-gray-700 text-gray-400' }}">
                            {{ $int->status }}
                        </span>
                    </td>
                    <td class="py-2 pr-4 text-{{ $int->inbound_enabled ? 'green' : 'gray' }}-400">
                        {{ $int->inbound_enabled ? 'Yes' : 'No' }}
                    </td>
                    <td class="py-2 pr-4 text-{{ $int->outbound_enabled ? 'amber' : 'gray' }}-400">
                        {{ $int->outbound_enabled ? 'Yes' : 'No' }}
                    </td>
                    <td class="py-2 text-gray-500">{{ $int->last_sync_at?->diffForHumans() ?? '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="py-4 text-center text-gray-500">No integrations registered.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Advisory Posture</h3>
            <ul class="text-xs text-gray-400 space-y-1 list-disc list-inside">
                <li>All integrations start as <code>inactive</code> — activation is analyst-initiated</li>
                <li>Inbound events are ingested read-only; no account modification</li>
                <li>Outbound case links are advisory-push only; no autonomous ticket closure</li>
                <li>All sync runs are dry_run=true by default and advisory_only=true</li>
                <li>Credentials are configuration-referenced only — never exposed via this interface</li>
            </ul>
        </div>
    </div>
</x-app-layout>
