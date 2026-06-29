<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Freeze Runs</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <table class="w-full text-sm border">
            <thead class="bg-gray-50">
                <tr>
                    <th class="p-2 text-left">Run ID</th>
                    <th class="p-2">Version</th>
                    <th class="p-2">State</th>
                    <th class="p-2">Score</th>
                    <th class="p-2">Completed</th>
                </tr>
            </thead>
            <tbody>
            @foreach($runs as $run)
            <tr class="border-t">
                <td class="p-2 font-mono text-xs">{{ $run->run_id }}</td>
                <td class="p-2 text-center">{{ $run->freeze_version }}</td>
                <td class="p-2 text-center">{{ strtoupper($run->run_state) }}</td>
                <td class="p-2 text-center">{{ $run->coverage_score !== null ? number_format($run->coverage_score * 100, 1).'%' : '—' }}</td>
                <td class="p-2 text-center">{{ $run->completed_at?->format('Y-m-d H:i') ?? '—' }}</td>
            </tr>
            @endforeach
            </tbody>
        </table>
        <div class="mt-4">{{ $runs->links() }}</div>
    </div>
</x-app-layout>
