<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Release Candidate Audit Timeline
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Release stabilization workflows are bounded, replay-safe, and audit-visible.
            No destructive deployment automation, uncontrolled feature expansion, or autonomous remediation is executed.
        </div>

        <div class="bg-gray-50 border border-gray-200 rounded p-3 text-sm text-gray-700">
            Append-only audit trail. No records can be deleted or updated. All actions are replay-safe and operator-visible.
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Audit ID</th>
                        <th class="px-4 py-2 text-left">Event</th>
                        <th class="px-4 py-2 text-left">Actor</th>
                        <th class="px-4 py-2 text-left">RC Version</th>
                        <th class="px-4 py-2 text-left">Autonomous</th>
                        <th class="px-4 py-2 text-left">Destructive</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($rcAudit as $entry)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($entry->rc_audit_id, 0, 18) }}…</td>
                            <td class="px-4 py-2">{{ $entry->audit_event_type }}</td>
                            <td class="px-4 py-2">{{ $entry->actor_type }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $entry->rc_version ?? '—' }}</td>
                            <td class="px-4 py-2 text-green-600">{{ $entry->autonomous_action_taken ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-green-600">{{ $entry->destructive_action_taken ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $entry->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-4 text-center text-gray-400 text-sm">No audit entries yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    </div>
</x-app-layout>
