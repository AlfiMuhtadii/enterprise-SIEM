<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Coverage Reports</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <p class="text-sm text-gray-500 mb-4">Pass threshold: {{ number_format($minPassScore * 100, 0) }}%</p>
        <table class="w-full text-sm border">
            <thead class="bg-gray-50">
                <tr>
                    <th class="p-2 text-left">Report ID</th>
                    <th class="p-2">Score</th>
                    <th class="p-2">Passing</th>
                    <th class="p-2">Failing</th>
                    <th class="p-2">Threshold</th>
                </tr>
            </thead>
            <tbody>
            @foreach($reports as $report)
            <tr class="border-t">
                <td class="p-2 font-mono text-xs">{{ $report->report_id }}</td>
                <td class="p-2 text-center">{{ number_format($report->overall_score * 100, 1) }}%</td>
                <td class="p-2 text-center text-green-600">{{ $report->passing_controls }}</td>
                <td class="p-2 text-center text-red-600">{{ $report->failing_controls }}</td>
                <td class="p-2 text-center {{ $report->meets_pass_threshold ? 'text-green-600' : 'text-red-600' }}">
                    {{ $report->meets_pass_threshold ? '✓ PASS' : '✗ FAIL' }}
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
        <div class="mt-4">{{ $reports->links() }}</div>
    </div>
</x-app-layout>
