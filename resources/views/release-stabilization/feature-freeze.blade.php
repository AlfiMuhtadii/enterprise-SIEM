<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Feature Freeze Governance
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Release stabilization workflows are bounded, replay-safe, and audit-visible.
            No destructive deployment automation, uncontrolled feature expansion, or autonomous remediation is executed.
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Audit ID</th>
                        <th class="px-4 py-2 text-left">Event</th>
                        <th class="px-4 py-2 text-left">Scope</th>
                        <th class="px-4 py-2 text-left">Subsystem</th>
                        <th class="px-4 py-2 text-left">Expansion Blocked</th>
                        <th class="px-4 py-2 text-left">Hotfix Allowed</th>
                        <th class="px-4 py-2 text-left">RC Version</th>
                        <th class="px-4 py-2 text-left">Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($freezeAudits as $audit)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($audit->freeze_audit_id, 0, 18) }}…</td>
                            <td class="px-4 py-2">{{ $audit->freeze_event }}</td>
                            <td class="px-4 py-2">{{ $audit->freeze_scope }}</td>
                            <td class="px-4 py-2">{{ $audit->subsystem ?? '—' }}</td>
                            <td class="px-4 py-2 text-green-600">{{ $audit->scope_expansion_blocked ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2">{{ $audit->hotfix_allowed ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $audit->rc_version ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $audit->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-4 text-center text-gray-400 text-sm">No freeze audits yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $freezeAudits->links() }}</div>

    </div>
</x-app-layout>
