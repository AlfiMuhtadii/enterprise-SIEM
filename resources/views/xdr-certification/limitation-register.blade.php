<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Operational Limitation Register
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        @if($cutoverBlocking > 0)
        <div class="bg-red-50 border border-red-300 rounded p-3 text-sm text-red-800">
            <strong>Cutover Blocked:</strong> {{ $cutoverBlocking }} limitation(s) are blocking cutover. All limitations are advisory-only and append-only.
        </div>
        @else
        <div class="bg-green-50 border border-green-200 rounded p-3 text-sm text-green-800">
            No cutover-blocking limitations registered.
        </div>
        @endif

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Limitation ID</th>
                        <th class="px-4 py-2 text-left">Category</th>
                        <th class="px-4 py-2 text-left">Severity</th>
                        <th class="px-4 py-2 text-left">Affected Domain</th>
                        <th class="px-4 py-2 text-left">Cutover Blocked</th>
                        <th class="px-4 py-2 text-left">Mitigation</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($limitations as $lim)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($lim->limitation_report_id, 0, 20) }}…</td>
                            <td class="px-4 py-2">{{ $lim->limitation_category }}</td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded text-xs {{ $lim->limitation_severity === 'high' ? 'bg-red-100 text-red-700' : ($lim->limitation_severity === 'medium' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">
                                    {{ $lim->limitation_severity }}
                                </span>
                            </td>
                            <td class="px-4 py-2">{{ $lim->affected_domain ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $lim->cutover_blocked ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2">{{ $lim->mitigation_available ? 'Available' : 'None' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $lim->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-4 text-center text-gray-400 text-sm">No limitations recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $limitations->links() }}</div>

    </div>
</x-app-layout>
