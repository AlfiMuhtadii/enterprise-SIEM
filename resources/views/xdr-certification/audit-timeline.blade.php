<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Certification Audit Timeline
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-gray-50 border border-gray-200 rounded p-3 text-sm text-gray-700">
            Append-only audit trail. No records can be deleted or updated. All actions are replay-safe.
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded shadow p-4">
                <h3 class="font-semibold text-gray-700 mb-3">Certification Audit (recent 50)</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead class="text-gray-400 uppercase">
                            <tr>
                                <th class="px-2 py-1 text-left">Event</th>
                                <th class="px-2 py-1 text-left">Actor</th>
                                <th class="px-2 py-1 text-left">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($certAudit as $entry)
                                <tr>
                                    <td class="px-2 py-1">{{ $entry->audit_event_type }}</td>
                                    <td class="px-2 py-1">{{ $entry->actor_type }}</td>
                                    <td class="px-2 py-1 text-gray-400">{{ $entry->created_at?->format('m-d H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-2 py-2 text-gray-400">No entries.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded shadow p-4">
                <h3 class="font-semibold text-gray-700 mb-3">Acceptance Audit (recent 50)</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead class="text-gray-400 uppercase">
                            <tr>
                                <th class="px-2 py-1 text-left">Event</th>
                                <th class="px-2 py-1 text-left">Actor</th>
                                <th class="px-2 py-1 text-left">Target</th>
                                <th class="px-2 py-1 text-left">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($acceptanceAudit as $entry)
                                <tr>
                                    <td class="px-2 py-1">{{ $entry->audit_event_type }}</td>
                                    <td class="px-2 py-1">{{ $entry->actor_type }}</td>
                                    <td class="px-2 py-1 text-gray-500">{{ Str::limit($entry->target_entity ?? '—', 20) }}</td>
                                    <td class="px-2 py-1 text-gray-400">{{ $entry->created_at?->format('m-d H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-2 py-2 text-gray-400">No entries.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
