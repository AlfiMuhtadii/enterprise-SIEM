<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Execution Plan — {{ $plan->name }}</h2>
        <p class="text-xs text-amber-400/80 mt-1">SOAR workflows are simulation-first and approval-gated. No autonomous enforcement or destructive response is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-4 gap-4 text-xs">
            @foreach([
                ['Status', $plan->status, 'text-cyan-300'],
                ['Blast Radius', number_format($plan->blast_radius_score, 2), 'text-orange-300'],
                ['Simulation', $plan->simulation_completed ? 'Complete' : 'Pending', $plan->simulation_completed ? 'text-green-300' : 'text-yellow-300'],
                ['Rollback Ready', $plan->rollback_ready ? 'Yes' : 'No', $plan->rollback_ready ? 'text-green-300' : 'text-red-300'],
            ] as [$label, $value, $color])
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">{{ $label }}</div>
                <div class="text-lg font-bold {{ $color }}">{{ $value }}</div>
            </div>
            @endforeach
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Execution Steps ({{ $steps->count() }})</h3>
                @forelse($steps as $step)
                <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                    <div class="flex items-center gap-2">
                        <span class="text-gray-500 w-5 text-right">{{ $step->step_order }}.</span>
                        <span class="text-gray-300">{{ $step->step_name }}</span>
                        <span class="text-gray-500">{{ $step->action_type }}</span>
                        @if($step->is_simulation_only)
                        <span class="ml-auto text-orange-400">[SIM-ONLY]</span>
                        @endif
                    </div>
                </div>
                @empty
                <p class="text-xs text-gray-500">No steps defined.</p>
                @endforelse
            </div>

            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Latest Simulation</h3>
                @if($simulation)
                <dl class="space-y-1 text-xs">
                    <div class="flex justify-between"><dt class="text-gray-400">Blast radius</dt><dd class="text-orange-300">{{ number_format($simulation->blast_radius_score, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-400">Affected entities</dt><dd class="text-gray-200">{{ $simulation->estimated_affected_entities }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-400">Rollback ready</dt><dd class="{{ $simulation->rollback_ready ? 'text-green-300' : 'text-red-300' }}">{{ $simulation->rollback_ready ? 'Yes' : 'No' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-400">Advisory</dt><dd class="text-green-300">{{ $simulation->is_advisory ? 'Yes' : 'No' }}</dd></div>
                </dl>
                @if($simulation->warnings)
                <div class="mt-2 space-y-1">
                    @foreach($simulation->warnings as $w)
                    <div class="text-xs text-yellow-300">⚠ {{ $w }}</div>
                    @endforeach
                </div>
                @endif
                @else
                <p class="text-xs text-gray-500">No simulation run yet.</p>
                @endif
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Audit Trail ({{ $audit->count() }} events)</h3>
            <div class="space-y-1 max-h-48 overflow-y-auto">
                @forelse($audit as $ev)
                <div class="flex items-center gap-2 py-1 text-xs">
                    <span class="text-gray-500 w-32 shrink-0">{{ $ev->created_at?->format('H:i:s') ?? '—' }}</span>
                    <span class="text-purple-300">{{ $ev->event_type }}</span>
                    <span class="text-gray-400">{{ $ev->description }}</span>
                </div>
                @empty
                <p class="text-xs text-gray-500">No audit events.</p>
                @endforelse
            </div>
        </div>

        <a href="{{ route('soar.governance-dashboard') }}" class="text-xs text-gray-400 hover:text-gray-300">← Back to Dashboard</a>

    </div>
</x-app-layout>
