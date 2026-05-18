<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Threat Hunting Dashboard</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Threat hunting is advisory-only and non-destructive.</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('threat-hunt.query-builder') }}"
                   class="text-sm px-3 py-1.5 rounded bg-cyan-700/30 border border-cyan-400/30 text-cyan-200 hover:bg-cyan-700/50">New Hunt</a>
                <a href="{{ route('threat-hunt.pivot-explorer') }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">Pivot Explorer</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Quick hunt links --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach ([
                    ['label'=>'Beacon Patterns', 'route'=>'threat-hunt.beacon-investigation', 'color'=>'text-red-300'],
                    ['label'=>'Persistence', 'route'=>'threat-hunt.persistence-investigation', 'color'=>'text-orange-300'],
                    ['label'=>'Chain Explorer', 'route'=>'threat-hunt.chain-explorer', 'color'=>'text-yellow-300'],
                    ['label'=>'Hunt History', 'route'=>'threat-hunt.history', 'color'=>'text-cyan-300'],
                ] as $link)
                <a href="{{ route($link['route']) }}" class="glass-card p-4 text-center hover:bg-cyan-100/10 transition">
                    <div class="text-sm font-semibold {{ $link['color'] }}">{{ $link['label'] }}</div>
                </a>
                @endforeach
            </div>

            {{-- Recent hunts --}}
            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Recent Hunts ({{ count($recentHunts) }})</h3>
                @if ($recentHunts->isEmpty())
                    <p class="text-cyan-400/50 text-sm">No hunts executed yet. <a href="{{ route('threat-hunt.query-builder') }}" class="text-cyan-400 hover:text-cyan-200">Start a new hunt →</a></p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-cyan-200/80">
                            <thead>
                                <tr class="text-left text-xs text-cyan-400/60 border-b border-cyan-200/10">
                                    <th class="pb-2 pr-4">Hunt ID</th>
                                    <th class="pb-2 pr-4">Title</th>
                                    <th class="pb-2 pr-4">Status</th>
                                    <th class="pb-2 pr-4">Results</th>
                                    <th class="pb-2 pr-4">Scope</th>
                                    <th class="pb-2">Executed</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentHunts as $hunt)
                                <tr class="border-b border-cyan-200/5 hover:bg-cyan-100/5">
                                    <td class="py-2 pr-4 font-mono text-xs">
                                        <a href="{{ route('threat-hunt.show', $hunt->hunt_id) }}" class="text-cyan-400 hover:text-cyan-200">{{ $hunt->hunt_id }}</a>
                                    </td>
                                    <td class="py-2 pr-4 text-xs">{{ $hunt->title }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="px-2 py-0.5 rounded text-xs
                                            {{ $hunt->status === 'completed' ? 'bg-green-900/40 text-green-300' :
                                               ($hunt->status === 'empty' ? 'bg-cyan-900/40 text-cyan-400' : 'bg-red-900/40 text-red-300') }}">
                                            {{ $hunt->status }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4 text-xs {{ $hunt->result_count > 0 ? 'text-cyan-100' : 'text-cyan-400/50' }}">{{ $hunt->result_count }}</td>
                                    <td class="py-2 pr-4 text-xs text-cyan-400/60">{{ $hunt->replay_scope }}</td>
                                    <td class="py-2 text-xs text-cyan-400/60">{{ $hunt->executed_at?->diffForHumans() }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">
                    All hunting queries are advisory-only and non-destructive. Queries read telemetry data only.
                    No process actions, no host modifications, no configuration changes are possible from this interface.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
