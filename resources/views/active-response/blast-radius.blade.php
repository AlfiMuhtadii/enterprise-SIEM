<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Blast Radius Visualization</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory impact assessment. No infrastructure changes.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Blast radius is deterministic and explainable. Score = action severity + entity observation amplification.
            No infrastructure probing performed.
        </div>

        <div class="rounded-lg border border-cyan-400/20 bg-gray-900/40 p-6">
            <div class="text-center mb-6">
                <div class="text-6xl font-bold text-{{ ($execution->blast_radius_score ?? 0) >= 0.7 ? 'red' : (($execution->blast_radius_score ?? 0) >= 0.4 ? 'amber' : 'green') }}-400">
                    {{ number_format(($execution->blast_radius_score ?? 0) * 100) }}%
                </div>
                <div class="text-gray-400 text-sm mt-1">Blast Radius Score</div>
                <div class="text-xs text-gray-500 mt-1">
                    Safety: {{ number_format(($execution->execution_safety_score ?? 0) * 100) }}% &middot;
                    Confidence: {{ number_format(($execution->execution_confidence_score ?? 0) * 100) }}%
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 text-sm">
                <div class="rounded bg-gray-800/30 p-3">
                    <h4 class="text-xs text-gray-400 uppercase mb-2">Action Details</h4>
                    <div class="space-y-1 text-xs text-gray-300">
                        <div>Type: <span class="text-cyan-300">{{ str_replace('_', ' ', $execution->action_type) }}</span></div>
                        <div>Target: <span class="font-mono text-cyan-300">{{ $execution->target_entity_key }}</span></div>
                        <div>Rollback: <span class="{{ $execution->rollback_supported ? 'text-green-400' : 'text-red-400' }}">{{ $execution->rollback_supported ? 'Supported' : 'Not supported' }}</span></div>
                        <div>Dual approval: <span class="{{ $execution->requires_dual_approval ? 'text-amber-400' : 'text-gray-400' }}">{{ $execution->requires_dual_approval ? 'Required' : 'Single approval' }}</span></div>
                    </div>
                </div>
                @if($simulation)
                <div class="rounded bg-gray-800/30 p-3">
                    <h4 class="text-xs text-gray-400 uppercase mb-2">Simulation Result</h4>
                    <div class="space-y-1 text-xs text-gray-300">
                        <div>ID: <span class="font-mono">{{ $simulation->simulation_id }}</span></div>
                        <div>Entities in radius: <span class="text-cyan-300">{{ count($simulation->blast_radius_entities) }}</span></div>
                        <div>Impacted services: <span class="text-cyan-300">{{ count($simulation->impacted_services) }}</span></div>
                        <div>Warnings: <span class="{{ count($simulation->warnings) > 0 ? 'text-red-300' : 'text-green-300' }}">{{ count($simulation->warnings) }}</span></div>
                    </div>
                </div>
                @endif
            </div>
        </div>

        <a href="{{ route('active-response.show', $execution->execution_id) }}" class="text-sm text-cyan-400 hover:underline">← Back to Execution</a>
    </div>
</x-app-layout>
