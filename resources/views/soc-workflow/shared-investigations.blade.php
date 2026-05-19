<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Shared Investigations</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Analyst-driven collaborative workflows only. No autonomous SOC operations.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        <form method="GET" class="flex gap-3">
            <select name="analyst_id" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                @foreach($analysts as $a)
                <option value="{{ $a->id }}" @selected($a->id == $analystId)>{{ $a->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">View</button>
        </form>

        @if($shared->isEmpty())
        <p class="text-sm text-gray-500">No investigations shared with this analyst.</p>
        @else
        <table class="w-full text-xs text-left text-gray-300">
            <thead class="text-gray-400 uppercase border-b border-gray-700">
                <tr><th class="py-2 pr-3">Investigation</th><th class="py-2 pr-3">Title</th><th class="py-2 pr-3">State</th><th class="py-2">Severity</th></tr>
            </thead>
            <tbody>
                @foreach($shared as $inv)
                <tr class="border-b border-gray-800">
                    <td class="py-1.5 pr-3 font-mono text-cyan-400">{{ $inv->investigation_id }}</td>
                    <td class="py-1.5 pr-3 text-gray-300">{{ $inv->title }}</td>
                    <td class="py-1.5 pr-3"><span class="px-1.5 py-0.5 rounded bg-gray-700 text-gray-400">{{ $inv->state }}</span></td>
                    <td class="py-1.5 {{ $inv->severity === 'critical' ? 'text-red-400' : 'text-gray-300' }}">{{ $inv->severity }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</x-app-layout>
