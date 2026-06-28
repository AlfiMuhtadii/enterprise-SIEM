<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Strict Mode Readiness History</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4">
        <div class="bg-white shadow rounded p-5">
            <table class="w-full text-sm">
                <thead><tr class="border-b"><th class="text-left py-1">Assessment ID</th><th class="py-1">Pass</th><th class="py-1">Warn</th><th class="py-1">Fail</th><th class="py-1">Score</th><th class="py-1">Status</th><th class="text-left py-1">Assessed By</th><th class="text-left py-1">Date</th></tr></thead>
                <tbody>
                @forelse($history as $row)
                <tr class="border-b">
                    <td class="py-1 font-mono text-xs">{{ $row->assessment_id }}</td>
                    <td class="py-1 text-center text-green-600">{{ $row->gates_passed }}</td>
                    <td class="py-1 text-center text-yellow-600">{{ $row->gates_warned }}</td>
                    <td class="py-1 text-center text-red-600">{{ $row->gates_failed }}</td>
                    <td class="py-1 text-center">{{ number_format($row->readiness_score * 100, 1) }}%</td>
                    <td class="py-1 text-center"><span class="px-2 py-0.5 rounded text-xs {{ $row->overall_status === 'READY' ? 'bg-green-100 text-green-700' : ($row->overall_status === 'WARN' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">{{ $row->overall_status }}</span></td>
                    <td class="py-1 text-xs">{{ $row->assessed_by ?? '—' }}</td>
                    <td class="py-1 text-xs text-gray-500">{{ $row->created_at }}</td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-4 text-center text-gray-500">No history yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
