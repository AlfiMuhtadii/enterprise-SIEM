<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">SOAR Simulation Runner</h2>
        <p class="text-xs text-amber-400/80 mt-1">SOAR workflows are simulation-first and approval-gated. No autonomous enforcement or destructive response is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Simulation Notice:</strong> Simulation produces deterministic output and blast-radius estimates. No live actions are executed during simulation.
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Allowed Phase 1 Actions ({{ count($allowedActions) }})</h3>
            <div class="flex flex-wrap gap-1.5">
                @foreach($allowedActions as $action)
                <span class="px-2 py-1 text-xs rounded
                    @if(str_starts_with($action, 'simulate_')) bg-orange-900/30 text-orange-300 border border-orange-700/40
                    @else bg-gray-800 text-gray-300 border border-gray-700 @endif">
                    {{ $action }}
                    @if(str_starts_with($action, 'simulate_'))
                    <span class="ml-1 text-orange-400/70">[SIM-ONLY]</span>
                    @endif
                </span>
                @endforeach
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Plans Available for Simulation ({{ $plans->count() }})</h3>
            <div class="space-y-2">
                @forelse($plans as $plan)
                <div class="flex items-center justify-between py-2 border-b border-gray-800 last:border-0 text-xs">
                    <div>
                        <span class="text-cyan-300 font-medium">{{ $plan->name }}</span>
                        <span class="ml-2 px-1.5 py-0.5 rounded
                            @if($plan->status === 'simulated') bg-green-900/40 text-green-300
                            @elseif($plan->status === 'simulation_pending') bg-yellow-900/40 text-yellow-300
                            @else bg-blue-900/40 text-blue-300 @endif">{{ $plan->status }}</span>
                    </div>
                    <div class="text-gray-400">
                        blast: {{ number_format($plan->blast_radius_score, 2) }}
                        <a href="{{ route('soar.execution-plan-viewer', ['planId' => $plan->plan_id]) }}" class="ml-2 text-blue-400 hover:text-blue-300">View Plan</a>
                    </div>
                </div>
                @empty
                <p class="text-xs text-gray-500">No plans awaiting simulation.</p>
                @endforelse
            </div>
        </div>

    </div>
</x-app-layout>
