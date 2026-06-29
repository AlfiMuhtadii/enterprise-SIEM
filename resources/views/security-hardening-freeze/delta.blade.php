<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Delta Reports</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <table class="w-full text-sm border">
            <thead class="bg-gray-50">
                <tr>
                    <th class="p-2 text-left">Delta ID</th>
                    <th class="p-2">Score Delta</th>
                    <th class="p-2">Regressed</th>
                    <th class="p-2">Improved</th>
                    <th class="p-2">Regression?</th>
                </tr>
            </thead>
            <tbody>
            @foreach($deltas as $delta)
            <tr class="border-t {{ $delta->regression_detected ? 'bg-red-50' : '' }}">
                <td class="p-2 font-mono text-xs">{{ $delta->delta_id }}</td>
                <td class="p-2 text-center {{ $delta->score_delta >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ ($delta->score_delta >= 0 ? '+' : '') . number_format($delta->score_delta * 100, 1) }}%
                </td>
                <td class="p-2 text-center text-red-600">{{ $delta->controls_regressed }}</td>
                <td class="p-2 text-center text-green-600">{{ $delta->controls_improved }}</td>
                <td class="p-2 text-center">{{ $delta->regression_detected ? '⚠ Yes' : 'No' }}</td>
            </tr>
            @endforeach
            @if($deltas->isEmpty())
            <tr><td colspan="5" class="p-4 text-center text-gray-400">No delta reports yet.</td></tr>
            @endif
            </tbody>
        </table>
        <div class="mt-4">{{ $deltas->links() }}</div>
    </div>
</x-app-layout>
