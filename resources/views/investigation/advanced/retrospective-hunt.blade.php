<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Retrospective Hunt Queries</h2>
        <p class="text-xs text-amber-400/80 mt-1">Historical replay queries across bounded domains. Advisory-only, replay-safe.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> All retrospective queries are replay-safe. Execution does not mutate source data or trigger enforcement.
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Saved Queries ({{ $queries->count() }})</h3>
            <div class="space-y-2">
                @forelse($queries as $query)
                <div class="flex items-center justify-between py-2 border-b border-gray-800 last:border-0 text-xs">
                    <div>
                        <span class="text-cyan-300 font-medium">{{ $query->name }}</span>
                        <span class="ml-2 text-gray-400">{{ $query->domain }}</span>
                        <span class="ml-2 px-1.5 py-0.5 rounded @if($query->status === 'executed') bg-green-900/40 text-green-300 @elseif($query->status === 'archived') bg-gray-700 text-gray-400 @else bg-blue-900/40 text-blue-300 @endif">
                            {{ $query->status }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2 text-gray-400">
                        @if($query->result_count !== null)
                        <span>{{ $query->result_count }} results</span>
                        @endif
                        <span>{{ $query->last_executed_at?->format('Y-m-d H:i') ?? 'not executed' }}</span>
                    </div>
                </div>
                @empty
                <p class="text-xs text-gray-500">No retrospective queries saved yet.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Available Domains ({{ count($domains) }})</h3>
            <div class="flex flex-wrap gap-1.5">
                @foreach($domains as $domain)
                <span class="px-2 py-1 text-xs rounded bg-gray-800 text-gray-300 border border-gray-700">{{ $domain }}</span>
                @endforeach
            </div>
        </div>

    </div>
</x-app-layout>
