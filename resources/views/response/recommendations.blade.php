<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="brand-chip">Response Planning</p>
                <h2 class="mt-2 text-xl font-semibold leading-tight text-main-ui">Recommended Actions</h2>
                @if ($entity)
                    <p class="mono-ui mt-1 text-xs text-cyan-200/55">
                        <span class="rounded border border-cyan-200/20 bg-cyan-100/5 px-1.5 py-0.5 mr-1.5">{{ $entity->entity_type }}</span>
                        {{ $entity->entity_key }}
                    </p>
                @elseif ($investigation)
                    <p class="mono-ui mt-1 text-xs text-cyan-200/55">Investigation: {{ $investigation->investigation_id ?? '—' }}</p>
                @endif
            </div>
            <a href="{{ route('response.index') }}"
               class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                ← Dashboard
            </a>
        </div>
    </x-slot>

    <div class="space-y-5">

        <div class="rounded-lg border border-yellow-400/20 bg-yellow-500/5 px-4 py-3 text-xs text-yellow-200/70">
            These are deterministic recommendations generated from risk scoring data.
            No actions are executed automatically. Analyst review and approval is required.
            <span class="mono-ui">advisory_only</span> items originate from shadow-domain detection.
        </div>

        {{-- Query form --}}
        <div class="glass-card p-4">
            <form method="GET" action="{{ route('response.recommendations') }}" class="flex flex-wrap gap-3 items-end">
                <div class="w-56">
                    <label class="mb-1 block text-xs text-cyan-200/60">Entity ID</label>
                    <input type="number" name="entity_id" value="{{ $entityId ?: '' }}"
                           class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 mono-ui focus:border-cyan-400/40 focus:outline-none"
                           placeholder="Entity database ID"/>
                </div>
                <div class="text-xs text-cyan-200/40 self-center">or</div>
                <div class="w-56">
                    <label class="mb-1 block text-xs text-cyan-200/60">Investigation ID</label>
                    <input type="number" name="investigation_id" value="{{ $investigationId ?: '' }}"
                           class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 mono-ui focus:border-cyan-400/40 focus:outline-none"
                           placeholder="Investigation database ID"/>
                </div>
                <button type="submit"
                        class="rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-500/25 transition">
                    Generate
                </button>
            </form>
        </div>

        @if (!empty($recs))
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        {{ count($recs) }} Recommended Action{{ count($recs) === 1 ? '' : 's' }}
                    </h3>
                    @can('soc:response.create')
                        <form method="POST" action="{{ route('response.store') }}">
                            @csrf
                            <input type="hidden" name="title" value="Response plan for {{ $entity?->entity_key ?? 'investigation ' . ($investigation?->investigation_id ?? '') }}"/>
                            <input type="hidden" name="investigation_id" value="{{ $investigationId ?: '' }}"/>
                            @if ($entityId)
                                <input type="hidden" name="entity_ids[]" value="{{ $entityId }}"/>
                            @endif
                            <button type="submit"
                                    class="rounded-lg border border-green-400/40 bg-green-500/10 px-3 py-1.5 text-xs text-green-200 hover:bg-green-500/20 transition">
                                Create Plan from These
                            </button>
                        </form>
                    @endcan
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-3">Action</th>
                            <th class="px-4 py-3">Target</th>
                            <th class="px-4 py-3">Reasoning</th>
                            <th class="px-4 py-3">Evidence</th>
                            <th class="px-4 py-3">Mode</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($recs as $rec)
                            @php
                                $actionColor = match(true) {
                                    str_contains($rec['action_type'], 'disable') || str_contains($rec['action_type'], 'isolate') => 'text-red-300 border-red-400/40 bg-red-500/10',
                                    str_contains($rec['action_type'], 'block')  || str_contains($rec['action_type'], 'revoke')   => 'text-orange-300 border-orange-400/40 bg-orange-500/10',
                                    str_contains($rec['action_type'], 'reset')  || str_contains($rec['action_type'], 'rotate')   => 'text-yellow-300 border-yellow-400/40 bg-yellow-500/10',
                                    str_contains($rec['action_type'], 'forensics') || str_contains($rec['action_type'], 'remove') => 'text-purple-300 border-purple-400/40 bg-purple-500/10',
                                    default => 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10',
                                };
                            @endphp
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-3">
                                    <span class="rounded border px-1.5 py-0.5 text-xs {{ $actionColor }} mono-ui">
                                        {{ str_replace('recommend_', '', $rec['action_type']) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-cyan-200/70">
                                    <span class="text-cyan-200/40">{{ $rec['target_entity_type'] }}</span>
                                    <span class="mono-ui ml-1">{{ Str::limit($rec['target_entity_key'], 32) }}</span>
                                </td>
                                <td class="px-4 py-3 max-w-xs">
                                    <p class="text-xs text-cyan-200/60 leading-relaxed">{{ Str::limit($rec['reasoning'], 80) }}</p>
                                </td>
                                <td class="px-4 py-3 text-xs text-cyan-200/50">
                                    @php $refs = $rec['evidence_refs'] ?? []; @endphp
                                    @if (!empty($refs['alert_ids']))
                                        <span>{{ count($refs['alert_ids']) }} alert(s)</span>
                                    @elseif (!empty($refs['incident_ids']))
                                        <span>{{ count($refs['incident_ids']) }} incident(s)</span>
                                    @else
                                        <span class="text-cyan-200/25">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if (!empty($rec['advisory_only']))
                                        <span class="rounded border border-yellow-400/30 bg-yellow-500/5 px-1.5 py-0.5 text-[10px] text-yellow-300/70 mono-ui">advisory_only</span>
                                    @else
                                        <span class="text-xs text-cyan-200/30">standard</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($entityId || $investigationId)
            <div class="glass-card p-8 text-center text-sm text-cyan-200/40">
                No recommendations generated. Entity may have no risk data or insufficient scoring signal.
            </div>
        @else
            <div class="glass-card p-8 text-center">
                <p class="text-sm text-cyan-200/40">Enter an entity ID or investigation ID to generate deterministic recommendations.</p>
                <p class="mt-1 text-xs text-cyan-200/25">Recommendations are generated from existing risk scoring data — no LLM, no randomness.</p>
            </div>
        @endif
    </div>
</x-app-layout>
