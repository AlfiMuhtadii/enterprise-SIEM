<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Release Blocker Explorer
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Release stabilization workflows are bounded, replay-safe, and audit-visible.
            No destructive deployment automation, uncontrolled feature expansion, or autonomous remediation is executed.
        </div>

        @if($criticalOpen > 0)
        <div class="bg-red-50 border border-red-400 rounded p-3 text-sm text-red-800">
            <strong>Release Blocked:</strong> {{ $criticalOpen }} critical blocker(s) open. No autonomous resolution.
        </div>
        @endif

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Blocker ID</th>
                        <th class="px-4 py-2 text-left">Category</th>
                        <th class="px-4 py-2 text-left">Severity</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Release Blocked</th>
                        <th class="px-4 py-2 text-left">RC Version</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($blockers as $blocker)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($blocker->blocker_report_id, 0, 18) }}…</td>
                            <td class="px-4 py-2">{{ $blocker->blocker_category }}</td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded text-xs {{ $blocker->blocker_severity === 'critical' ? 'bg-red-100 text-red-700' : ($blocker->blocker_severity === 'high' ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700') }}">
                                    {{ $blocker->blocker_severity }}
                                </span>
                            </td>
                            <td class="px-4 py-2">{{ $blocker->blocker_state }}</td>
                            <td class="px-4 py-2">{{ $blocker->release_blocked ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $blocker->rc_version ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $blocker->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-4 text-center text-gray-400 text-sm">No blockers registered.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $blockers->links() }}</div>

    </div>
</x-app-layout>
