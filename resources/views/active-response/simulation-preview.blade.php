<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Simulation Preview</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Simulation only. No infrastructure was changed. No commands executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Simulation Notice:</strong> This is a read-only blast radius preview. Zero infrastructure changes were made.
            Review all warnings before proceeding to execution.
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-3 text-center">
                <div class="text-2xl font-bold text-{{ ($execution->blast_radius_score ?? 0) > 0.6 ? 'red' : 'cyan' }}-300">{{ number_format(($execution->blast_radius_score ?? 0) * 100) }}%</div>
                <div class="text-xs text-gray-400 mt-1">Blast Radius</div>
            </div>
            <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-3 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ number_format(($execution->execution_safety_score ?? 0) * 100) }}%</div>
                <div class="text-xs text-gray-400 mt-1">Safety Score</div>
            </div>
            <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-3 text-center">
                <div class="text-2xl font-bold {{ $simulation?->rollback_available ? 'text-green-300' : 'text-red-400' }}">{{ $simulation?->rollback_available ? 'Yes' : 'No' }}</div>
                <div class="text-xs text-gray-400 mt-1">Rollback Available</div>
            </div>
            <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-3 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ count($simulation?->impacted_services ?? []) }}</div>
                <div class="text-xs text-gray-400 mt-1">Impacted Services</div>
            </div>
        </div>

        @if($simulation)
        @if(!empty($simulation->warnings))
        <div class="rounded-lg border border-red-400/20 bg-red-900/10 p-4">
            <h3 class="text-sm font-semibold text-red-300 mb-2">Warnings ({{ count($simulation->warnings) }})</h3>
            @foreach($simulation->warnings as $w)
            <div class="text-xs text-red-200 mb-1 flex gap-2"><span class="text-red-400">⚠</span>{{ $w }}</div>
            @endforeach
        </div>
        @endif

        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Blast Radius Entities ({{ count($simulation->blast_radius_entities) }})</h3>
            <div class="space-y-1">
                @foreach($simulation->blast_radius_entities as $entity)
                <div class="text-xs p-1.5 rounded bg-gray-800/30 flex gap-3 text-gray-300">
                    <span class="text-{{ ($entity['direct'] ?? false) ? 'cyan' : 'gray' }}-400 font-medium">{{ ($entity['direct'] ?? false) ? 'DIRECT' : 'RELATED' }}</span>
                    <span class="font-mono">{{ $entity['entity_key'] ?? '?' }}</span>
                    <span class="text-gray-500">{{ $entity['entity_type'] ?? '' }}</span>
                    @if(!empty($entity['relationship']))<span class="text-gray-600">via {{ $entity['relationship'] }}</span>@endif
                </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Impacted Services</h3>
            <div class="flex flex-wrap gap-2">
                @foreach($simulation->impacted_services as $svc)
                <span class="px-2 py-0.5 rounded text-xs bg-gray-800 border border-gray-600 text-gray-300">{{ str_replace('_', ' ', $svc) }}</span>
                @endforeach
            </div>
        </div>

        @if($simulation->simulation_notes)
        <p class="text-xs text-gray-500 italic">{{ $simulation->simulation_notes }}</p>
        @endif
        @else
        <p class="text-sm text-gray-500">No simulation has been run yet.</p>
        @endif

        <div class="flex gap-3">
            <a href="{{ route('active-response.show', $execution->execution_id) }}" class="text-sm text-cyan-400 hover:underline">← Back to Execution</a>
            <a href="{{ route('active-response.blast-radius', $execution->execution_id) }}" class="text-sm text-cyan-400/70 hover:underline">Blast Radius Detail →</a>
        </div>
    </div>
</x-app-layout>
