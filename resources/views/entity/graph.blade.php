<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="brand-chip">Entity Graph</p>
                <h2 class="mt-2 text-xl font-semibold leading-tight text-main-ui">Adjacency Pivot</h2>
                <p class="mono-ui mt-1 truncate text-xs text-cyan-200/55">
                    <span class="rounded border border-cyan-200/20 bg-cyan-100/5 px-1.5 py-0.5 mr-1.5">{{ $entity->entity_type }}</span>
                    {{ $entity->entity_key }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('entity.show', $entity->id) }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    ← Detail
                </a>
                <a href="{{ route('api.entities.relationships', $entity->id) }}" target="_blank" rel="noopener"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    JSON
                </a>
            </div>
        </div>
    </x-slot>

    @php
        $nodes    = collect($adjacency['nodes']);
        $edges    = collect($adjacency['edges']);
        $focalId  = $entity->id;
        $peers    = $nodes->filter(fn($n) => $n['id'] !== $focalId);

        $typeColor = fn(string $t) => match($t) {
            'user'      => 'border-cyan-400/50 bg-cyan-500/10 text-cyan-300',
            'host'      => 'border-blue-400/50 bg-blue-500/10 text-blue-300',
            'process'   => 'border-purple-400/50 bg-purple-500/10 text-purple-300',
            'ip'        => 'border-orange-400/50 bg-orange-500/10 text-orange-300',
            'domain'    => 'border-green-400/50 bg-green-500/10 text-green-300',
            'file_hash' => 'border-red-400/50 bg-red-500/10 text-red-300',
            'alert'     => 'border-yellow-400/50 bg-yellow-500/10 text-yellow-300',
            'incident'  => 'border-pink-400/50 bg-pink-500/10 text-pink-300',
            'trace'     => 'border-gray-400/50 bg-gray-500/10 text-gray-300',
            default     => 'border-cyan-200/20 bg-black/20 text-cyan-100/50',
        };

        // Build edge lookup: peerId => [{relationship_type, obs_count, confidence, trace_id, direction}]
        $edgesByPeer = [];
        foreach ($edges as $edge) {
            $srcId = (int) $edge['source'];
            $tgtId = (int) $edge['target'];
            $peer  = ($srcId === $focalId) ? $tgtId : $srcId;
            $dir   = ($srcId === $focalId) ? '→' : '←';
            $edgesByPeer[$peer][] = [
                'rel'        => $edge['relationship_type'],
                'obs'        => $edge['observation_count'],
                'conf'       => $edge['confidence'],
                'trace_id'   => $edge['trace_id'] ?? null,
                'direction'  => $dir,
            ];
        }
    @endphp

    <div class="space-y-6">

        {{-- Stats strip --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach ([
                ['label' => 'Total Nodes', 'value' => $nodes->count()],
                ['label' => 'Relationships', 'value' => $edges->count()],
                ['label' => 'Direct Peers', 'value' => $peers->count()],
            ] as $m)
                <div class="glass-card px-4 py-3">
                    <p class="text-xs text-cyan-200/45">{{ $m['label'] }}</p>
                    <p class="mt-1 text-lg font-semibold text-cyan-50 mono-ui">{{ $m['value'] }}</p>
                </div>
            @endforeach
        </div>

        @if ($nodes->isEmpty())
            <div class="glass-card p-8 text-center text-sm text-cyan-200/40">
                No graph data available for this entity yet.
                <p class="mt-1 text-xs text-cyan-200/25">Run entity projection from pipeline data to populate the graph.</p>
            </div>
        @else

            {{-- Focal entity --}}
            <div class="glass-card p-5">
                <p class="mb-3 text-xs uppercase tracking-[0.12em] text-cyan-200/40">Focal Entity</p>
                <div class="inline-flex items-center gap-3 rounded-xl border {{ $typeColor($entity->entity_type) }} px-5 py-3">
                    <div>
                        <p class="text-xs text-current/60 mb-0.5">{{ $entity->entity_type }}</p>
                        <p class="mono-ui text-sm font-semibold">{{ Str::limit($entity->entity_key, 52) }}</p>
                        @if ($entity->display_name && $entity->display_name !== $entity->entity_key)
                            <p class="text-xs opacity-60 mt-0.5">{{ $entity->display_name }}</p>
                        @endif
                    </div>
                    @if ($entity->risk_score !== null)
                        <div class="shrink-0 rounded-full border border-current/30 px-2 py-0.5 text-xs font-bold">
                            R{{ number_format($entity->risk_score, 1) }}
                        </div>
                    @endif
                </div>
            </div>

            {{-- Adjacency pivot table --}}
            @if ($peers->isNotEmpty())
                <div class="glass-card overflow-hidden">
                    <div class="border-b border-cyan-100/10 px-5 py-3">
                        <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                            Connected Entities — Pivot View
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                                <th class="px-5 py-3">Dir</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Entity Key</th>
                                <th class="px-4 py-3">Relationship</th>
                                <th class="px-4 py-3 text-center">Obs</th>
                                <th class="px-4 py-3 text-center">Conf</th>
                                <th class="px-4 py-3">Trace</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/5">
                            @foreach ($peers as $peer)
                                @php $peerEdges = $edgesByPeer[$peer['id']] ?? []; @endphp
                                @foreach ($peerEdges as $idx => $e)
                                    <tr class="hover:bg-cyan-100/3 transition">
                                        @if ($idx === 0)
                                            <td class="px-5 py-2.5" @if(count($peerEdges) > 1) rowspan="{{ count($peerEdges) }}" @endif>
                                                <span class="text-xs {{ $e['direction'] === '→' ? 'text-cyan-400' : 'text-orange-400' }}">
                                                    {{ $e['direction'] }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2.5" @if(count($peerEdges) > 1) rowspan="{{ count($peerEdges) }}" @endif>
                                                <span class="rounded border px-1.5 py-0.5 text-xs {{ $typeColor($peer['entity_type']) }}">
                                                    {{ $peer['entity_type'] }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2.5" @if(count($peerEdges) > 1) rowspan="{{ count($peerEdges) }}" @endif>
                                                <span class="mono-ui text-xs text-cyan-200/75">{{ Str::limit($peer['entity_key'], 38) }}</span>
                                            </td>
                                        @endif
                                        <td class="px-4 py-2.5">
                                            <span class="mono-ui text-xs text-cyan-200/60">{{ $e['rel'] }}</span>
                                        </td>
                                        <td class="px-4 py-2.5 text-center text-xs text-cyan-200/60">{{ $e['obs'] }}</td>
                                        <td class="px-4 py-2.5 text-center text-xs text-cyan-200/50">{{ number_format($e['conf'], 2) }}</td>
                                        <td class="px-4 py-2.5">
                                            @if ($e['trace_id'])
                                                <a href="{{ route('traces.show', $e['trace_id']) }}"
                                                   class="mono-ui text-xs text-cyan-400 hover:text-cyan-200 transition">
                                                    {{ Str::limit($e['trace_id'], 18) }}
                                                </a>
                                            @else
                                                <span class="text-xs text-cyan-200/20">—</span>
                                            @endif
                                        </td>
                                        @if ($idx === 0)
                                            <td class="px-4 py-2.5" @if(count($peerEdges) > 1) rowspan="{{ count($peerEdges) }}" @endif>
                                                <a href="{{ route('entity.graph', $peer['id']) }}"
                                                   class="text-xs text-cyan-400 hover:text-cyan-200 transition">Pivot →</a>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>

                {{-- Visual hub-and-spoke (lightweight CSS only) --}}
                <div class="glass-card p-5">
                    <p class="mb-4 text-xs uppercase tracking-[0.12em] text-cyan-200/40">Hub-and-Spoke Overview</p>
                    <div class="flex flex-wrap gap-3 items-start">
                        {{-- Focal hub --}}
                        <div class="flex flex-col items-center gap-1">
                            <div class="rounded-xl border-2 {{ $typeColor($entity->entity_type) }} px-4 py-2.5 text-center min-w-32">
                                <p class="text-xs opacity-60">{{ $entity->entity_type }}</p>
                                <p class="mono-ui text-xs font-semibold mt-0.5">{{ Str::limit($entity->entity_key, 22) }}</p>
                            </div>
                            <div class="text-[10px] text-cyan-200/30">focal</div>
                        </div>

                        {{-- Spokes --}}
                        @foreach ($peers->take(12) as $peer)
                            <div class="flex items-center gap-1.5">
                                <span class="text-cyan-200/25 text-sm">—</span>
                                <div class="flex flex-col items-center gap-1">
                                    <a href="{{ route('entity.graph', $peer['id']) }}"
                                       class="rounded-lg border {{ $typeColor($peer['entity_type']) }} px-3 py-2 text-center hover:opacity-80 transition min-w-24">
                                        <p class="text-[10px] opacity-60">{{ $peer['entity_type'] }}</p>
                                        <p class="mono-ui text-[10px] font-medium mt-0.5">{{ Str::limit($peer['entity_key'], 20) }}</p>
                                    </a>
                                    @php
                                        $pEdges = $edgesByPeer[$peer['id']] ?? [];
                                        $relLabel = implode(', ', array_unique(array_column($pEdges, 'rel')));
                                    @endphp
                                    <div class="text-[9px] text-cyan-200/30 text-center max-w-24 leading-tight">
                                        {{ Str::limit($relLabel, 24) }}
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        @if ($peers->count() > 12)
                            <div class="flex items-center">
                                <span class="text-xs text-cyan-200/30">+ {{ $peers->count() - 12 }} more</span>
                            </div>
                        @endif
                    </div>
                </div>
            @else
                <div class="glass-card p-6 text-center text-sm text-cyan-200/40">
                    This entity has no connected peers in the graph yet.
                </div>
            @endif

        @endif

    </div>
</x-app-layout>
