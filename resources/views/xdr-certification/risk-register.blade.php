<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Production Risk Register
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        @if($openCritical > 0)
        <div class="bg-red-50 border border-red-400 rounded p-3 text-sm text-red-800">
            <strong>Critical Risk Alert:</strong> {{ $openCritical }} open critical risk(s). Cutover is blocked. No autonomous mitigation.
        </div>
        @else
        <div class="bg-green-50 border border-green-200 rounded p-3 text-sm text-green-800">
            No open critical risks. All mitigations are analyst-driven.
        </div>
        @endif

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Risk ID</th>
                        <th class="px-4 py-2 text-left">Category</th>
                        <th class="px-4 py-2 text-left">Severity</th>
                        <th class="px-4 py-2 text-left">Score</th>
                        <th class="px-4 py-2 text-left">State</th>
                        <th class="px-4 py-2 text-left">Cutover Blocker</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($risks as $risk)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ substr($risk->risk_id, 0, 20) }}…</td>
                            <td class="px-4 py-2">{{ $risk->risk_category }}</td>
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 rounded text-xs {{ $risk->risk_severity === 'critical' ? 'bg-red-100 text-red-700' : ($risk->risk_severity === 'high' ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700') }}">
                                    {{ $risk->risk_severity }}
                                </span>
                            </td>
                            <td class="px-4 py-2">{{ number_format($risk->risk_score, 1) }}/10</td>
                            <td class="px-4 py-2">{{ $risk->risk_state }}</td>
                            <td class="px-4 py-2">{{ $risk->cutover_blocker ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $risk->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-4 text-center text-gray-400 text-sm">No risks registered.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $risks->links() }}</div>

    </div>
</x-app-layout>
