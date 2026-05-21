<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Deployment Dependency Graph</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Operational hardening and recovery tooling only. No autonomous infrastructure mutation.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-6xl mx-auto space-y-6">
        @php
        $statusByService = $snapshots->keyBy('service_name');
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach($graph['nodes'] as $node)
            @php $svc = $statusByService[$node['id']] ?? null; $state = $svc ? $svc->health_state : 'unknown'; @endphp
            <div class="glass-card p-3 flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium text-cyan-200">{{ $node['id'] }}</div>
                    <div class="text-xs text-gray-500">{{ $node['type'] }} · {{ $node['role'] }}</div>
                </div>
                <span class="px-1.5 py-0.5 rounded text-xs
                    {{ $state === 'healthy' ? 'bg-green-900/40 text-green-300' :
                       ($state === 'degraded' ? 'bg-amber-900/40 text-amber-300' :
                       ($state === 'down' ? 'bg-red-900/40 text-red-300' : 'bg-gray-700 text-gray-500')) }}">
                    {{ $state }}
                </span>
            </div>
            @endforeach
        </div>

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Event Flow Dependencies</h3>
            <div class="space-y-1.5">
                @foreach($graph['edges'] as $edge)
                <div class="flex items-center gap-3 text-xs">
                    @php $fromState = $statusByService[$edge['from']] ?? null; $toState = $statusByService[$edge['to']] ?? null; @endphp
                    <span class="w-40 text-right font-mono {{ ($fromState && $fromState->health_state !== 'healthy') ? 'text-amber-400' : 'text-cyan-300' }}">
                        {{ $edge['from'] }}
                    </span>
                    <span class="text-gray-600">→</span>
                    <span class="w-40 font-mono {{ ($toState && $toState->health_state !== 'healthy') ? 'text-amber-400' : 'text-gray-300' }}">
                        {{ $edge['to'] }}
                    </span>
                    <span class="text-gray-500 text-xs italic">{{ $edge['label'] }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
