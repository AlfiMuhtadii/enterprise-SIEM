<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="brand-chip">Response Plan</p>
                <h2 class="mt-2 text-xl font-semibold leading-tight text-main-ui">
                    <span class="mono-ui text-cyan-200/60 text-base">{{ $plan->plan_id }}</span>
                    — {{ $plan->title }}
                </h2>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('response.index') }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    ← Plans
                </a>
                <a href="{{ route('api.response-plans.show', $plan->id) }}" target="_blank" rel="noopener"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    JSON
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-5">

        @if (session('success'))
            <div class="rounded-lg border border-green-400/30 bg-green-500/8 px-4 py-2.5 text-xs text-green-300">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-lg border border-red-400/30 bg-red-500/8 px-4 py-2.5 text-xs text-red-300">{{ $errors->first() }}</div>
        @endif

        <div class="rounded-lg border border-yellow-400/20 bg-yellow-500/5 px-4 py-2.5 text-xs text-yellow-200/60">
            Advisory only — no automated execution. <strong>completed_documented</strong> = analyst manually documented external action.
        </div>

        {{-- Plan header --}}
        @php
            $sc = match($plan->state) {
                'draft'               => 'border-gray-400/60 bg-gray-500/10 text-gray-200',
                'pending_approval'    => 'border-yellow-400/60 bg-yellow-500/10 text-yellow-200',
                'approved'            => 'border-green-400/60 bg-green-500/10 text-green-200',
                'rejected'            => 'border-red-400/60 bg-red-500/10 text-red-200',
                'completed_documented'=> 'border-cyan-400/60 bg-cyan-500/10 text-cyan-200',
                default               => 'border-gray-400/30 bg-black/20 text-gray-400',
            };
        @endphp
        <div class="glass-card p-5">
            <div class="flex flex-wrap items-start gap-5">
                <div>
                    <p class="text-xs text-cyan-200/40 mb-1.5">State</p>
                    <span class="rounded-lg border-2 px-3 py-1.5 text-sm font-semibold {{ $sc }}">
                        {{ str_replace('_', ' ', strtoupper($plan->state)) }}
                    </span>
                </div>
                <div class="flex-1 min-w-0 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ([
                        ['label' => 'Risk Level', 'value' => strtoupper($plan->risk_level ?? '—')],
                        ['label' => 'Created By', 'value' => $plan->creator_name ?? '—'],
                        ['label' => 'Approver',   'value' => $plan->approver_name ?? 'Unassigned'],
                        ['label' => 'Actions',    'value' => $actions->count()],
                    ] as $m)
                        <div class="glass-card px-3 py-2.5">
                            <p class="text-xs text-cyan-200/40">{{ $m['label'] }}</p>
                            <p class="mt-0.5 text-sm font-semibold text-cyan-50">{{ $m['value'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
            @if ($plan->description)
                <p class="mt-4 text-sm text-cyan-200/60 border-t border-cyan-100/10 pt-4">{{ $plan->description }}</p>
            @endif
            <div class="mt-3 flex flex-wrap gap-3 text-xs">
                @if ($plan->trace_id)
                    <span class="text-cyan-200/40">Trace:</span>
                    <a href="{{ route('traces.show', $plan->trace_id) }}" class="mono-ui text-cyan-400 hover:text-cyan-200 transition">{{ $plan->trace_id }}</a>
                @endif
                @if ($investigation)
                    <span class="text-cyan-200/40">Investigation:</span>
                    <a href="{{ route('investigation.show', $investigation->id) }}" class="mono-ui text-cyan-400 hover:text-cyan-200 transition">{{ $investigation->investigation_id }}</a>
                @endif
            </div>
        </div>

        {{-- Action controls --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @if ($plan->state === 'draft')
                @can('soc:response.create')
                    <form method="POST" action="{{ route('response.submit', $plan->id) }}">
                        @csrf
                        <button type="submit" class="w-full rounded-lg border border-yellow-400/40 bg-yellow-500/10 px-4 py-2.5 text-sm text-yellow-200 hover:bg-yellow-500/20 transition">
                            Submit for Approval
                        </button>
                    </form>
                @endcan
            @endif

            @if ($plan->state === 'pending_approval')
                @can('soc:response.approve')
                    <form method="POST" action="{{ route('response.approve', $plan->id) }}" class="space-y-2">
                        @csrf
                        <input type="text" name="notes" placeholder="Approval notes (optional)"
                               class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-xs text-cyan-50 focus:outline-none"/>
                        <button type="submit" class="w-full rounded-lg border border-green-400/40 bg-green-500/10 px-4 py-2 text-sm text-green-200 hover:bg-green-500/20 transition">
                            Approve Plan
                        </button>
                    </form>
                    <form method="POST" action="{{ route('response.reject', $plan->id) }}" class="space-y-2">
                        @csrf
                        <input type="text" name="reason" required placeholder="Rejection reason *"
                               class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-xs text-cyan-50 focus:outline-none"/>
                        <button type="submit" class="w-full rounded-lg border border-red-400/40 bg-red-500/10 px-4 py-2 text-sm text-red-200 hover:bg-red-500/20 transition">
                            Reject Plan
                        </button>
                    </form>
                @endcan
            @endif

            @if ($plan->state === 'approved')
                @can('soc:response.create')
                    <form method="POST" action="{{ route('response.complete', $plan->id) }}" class="space-y-2">
                        @csrf
                        <input type="text" name="notes" placeholder="Completion notes (what was done externally)"
                               class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-xs text-cyan-50 focus:outline-none"/>
                        <button type="submit" class="w-full rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-500/25 transition">
                            Mark Completed (Documented)
                        </button>
                    </form>
                @endcan
            @endif

            @if (!in_array($plan->state, ['completed_documented', 'cancelled']))
                @can('soc:response.create')
                    <form method="POST" action="{{ route('response.cancel', $plan->id) }}" onsubmit="return confirm('Cancel this plan?')">
                        @csrf
                        <button type="submit" class="w-full rounded-lg border border-gray-400/30 bg-gray-500/10 px-4 py-2.5 text-sm text-gray-400 hover:text-gray-200 transition">
                            Cancel Plan
                        </button>
                    </form>
                @endcan
            @endif
        </div>

        {{-- Recommended actions --}}
        @if ($actions->isNotEmpty())
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        Response Actions ({{ $actions->count() }}) — Planning Only
                    </h3>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-3">Action</th>
                            <th class="px-4 py-3">Target</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Mode</th>
                            <th class="px-4 py-3">Reasoning</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($actions as $action)
                            @php
                                $ac = match(true) {
                                    str_contains($action->action_type, 'disable') || str_contains($action->action_type, 'isolate') => 'text-red-300 border-red-400/40 bg-red-500/10',
                                    str_contains($action->action_type, 'block')   || str_contains($action->action_type, 'revoke')  => 'text-orange-300 border-orange-400/40 bg-orange-500/10',
                                    str_contains($action->action_type, 'reset')   || str_contains($action->action_type, 'rotate')  => 'text-yellow-300 border-yellow-400/40 bg-yellow-500/10',
                                    str_contains($action->action_type, 'forensics') || str_contains($action->action_type, 'remove') => 'text-purple-300 border-purple-400/40 bg-purple-500/10',
                                    default => 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10',
                                };
                                $sts = match($action->status) {
                                    'approved'             => 'text-green-300 border-green-400/40 bg-green-500/10',
                                    'completed_documented' => 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10',
                                    'rejected'             => 'text-red-300 border-red-400/40 bg-red-500/10',
                                    'waived'               => 'text-gray-400 border-gray-400/30 bg-gray-500/10',
                                    default                => 'text-yellow-300 border-yellow-400/30 bg-yellow-500/8',
                                };
                            @endphp
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-2.5">
                                    <span class="rounded border px-1.5 py-0.5 text-xs {{ $ac }} mono-ui">
                                        {{ str_replace('recommend_', '', $action->action_type) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/65">
                                    <span class="text-cyan-200/40">{{ $action->target_entity_type }}</span>
                                    <span class="mono-ui ml-1">{{ Str::limit($action->target_entity_key, 28) }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded border px-1.5 py-0.5 text-xs {{ $sts }}">{{ str_replace('_',' ',$action->status) }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    @if ($action->advisory_only)
                                        <span class="rounded border border-yellow-400/30 bg-yellow-500/5 px-1.5 py-0.5 text-[10px] text-yellow-300/70 mono-ui">advisory_only</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/55 max-w-xs">{{ Str::limit($action->reasoning, 60) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="grid gap-5 lg:grid-cols-2">

            {{-- Approval Timeline --}}
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Approval Timeline</h3>
                    <a href="{{ route('api.response-plans.timeline', $plan->id) }}" target="_blank" rel="noopener" class="text-xs text-cyan-400 hover:text-cyan-200 transition">JSON</a>
                </div>
                @if ($timeline->isEmpty())
                    <div class="px-5 py-4 text-xs text-cyan-200/30">No timeline events.</div>
                @else
                    <div class="px-5 py-4 space-y-3 max-h-64 overflow-y-auto">
                        @foreach ($timeline->sortByDesc('occurred_at') as $evt)
                            @php
                                $icon = match($evt->timeline_type ?? '') {
                                    'approval' => match($evt->decision ?? '') {
                                        'approved'   => '✓',
                                        'rejected'   => '✗',
                                        'submitted'  => '→',
                                        'completed'  => '◎',
                                        'cancelled'  => '×',
                                        default      => '●',
                                    },
                                    'note' => '📝',
                                    default => '●',
                                };
                                $iconColor = match($evt->decision ?? '') {
                                    'approved'  => 'text-green-400',
                                    'rejected'  => 'text-red-400',
                                    'completed' => 'text-cyan-400',
                                    default     => 'text-cyan-400/60',
                                };
                            @endphp
                            <div class="flex gap-3 text-xs">
                                <span class="shrink-0 w-4 {{ $iconColor }}">{{ $icon }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-cyan-200/80 mono-ui">
                                            {{ $evt->decision ?? $evt->note_type ?? 'note' }}
                                        </span>
                                        @if ($evt->from_state ?? null)
                                            <span class="text-cyan-200/35">{{ $evt->from_state }} → {{ $evt->to_state }}</span>
                                        @endif
                                        @if ($evt->actor_name ?? null)
                                            <span class="text-cyan-200/40">by {{ $evt->actor_name }}</span>
                                        @endif
                                        <span class="ml-auto mono-ui text-cyan-200/30">
                                            {{ \Carbon\Carbon::parse($evt->occurred_at)->format('Y-m-d H:i') }}
                                        </span>
                                    </div>
                                    @if (!empty($evt->notes) || !empty($evt->body))
                                        <p class="mt-0.5 text-cyan-200/50 italic">{{ Str::limit($evt->notes ?? $evt->body, 80) }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Notes + add note --}}
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Notes ({{ $notes->count() }})</h3>
                </div>
                @can('soc:response.note')
                    <div class="border-b border-cyan-100/10 px-5 py-3">
                        <form method="POST" action="{{ route('response.notes.store', $plan->id) }}" class="space-y-2">
                            @csrf
                            <select name="note_type" class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                                @foreach (\App\Models\ResponsePlan::NOTE_TYPES as $nt)
                                    <option value="{{ $nt }}">{{ str_replace('_',' ',ucfirst($nt)) }}</option>
                                @endforeach
                            </select>
                            <textarea name="body" rows="2" required
                                      class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-xs text-cyan-50 focus:outline-none"
                                      placeholder="Note body..."></textarea>
                            <button type="submit" class="w-full rounded-lg border border-cyan-400/30 bg-cyan-500/10 px-3 py-1.5 text-xs text-cyan-200 hover:bg-cyan-500/20 transition">
                                Add Note
                            </button>
                        </form>
                    </div>
                @endcan
                @if ($notes->isEmpty())
                    <div class="px-5 py-4 text-xs text-cyan-200/30">No notes.</div>
                @else
                    <div class="divide-y divide-cyan-100/5 max-h-56 overflow-y-auto">
                        @foreach ($notes as $note)
                            <div class="px-5 py-3">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="rounded border border-cyan-200/20 bg-cyan-100/5 px-1.5 py-0.5 text-[10px] text-cyan-200/60">{{ $note->note_type }}</span>
                                    <span class="ml-auto text-[10px] text-cyan-200/30 mono-ui">{{ $note->author_name ?? 'system' }} · {{ \Carbon\Carbon::parse($note->created_at)->format('Y-m-d H:i') }}</span>
                                </div>
                                <p class="text-xs text-cyan-200/70 leading-relaxed">{{ $note->body }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Linked evidence --}}
        @if ($linkedAlerts->isNotEmpty() || $linkedEntities->isNotEmpty())
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Linked Evidence</h3>
                </div>
                <div class="grid gap-0 lg:grid-cols-2 divide-x divide-cyan-100/10">
                    @if ($linkedAlerts->isNotEmpty())
                        <div>
                            <p class="px-5 py-2.5 text-xs text-cyan-200/40 border-b border-cyan-100/10">{{ $linkedAlerts->count() }} Alert(s)</p>
                            @foreach ($linkedAlerts->take(5) as $al)
                                <div class="px-5 py-2.5 border-b border-cyan-100/5 flex items-center gap-3 text-xs">
                                    @php $sev=$al->severity??'medium'; $sc2=match($sev){'critical'=>'text-red-300','high'=>'text-orange-300','medium'=>'text-yellow-300',default=>'text-cyan-200/50'}; @endphp
                                    <span class="font-semibold {{ $sc2 }}">{{ strtoupper($sev) }}</span>
                                    <span class="mono-ui text-cyan-200/65 flex-1">{{ $al->alert_type??'—' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if ($linkedEntities->isNotEmpty())
                        <div>
                            <p class="px-5 py-2.5 text-xs text-cyan-200/40 border-b border-cyan-100/10">{{ $linkedEntities->count() }} Entity(ies)</p>
                            @foreach ($linkedEntities->take(5) as $ent)
                                <div class="px-5 py-2.5 border-b border-cyan-100/5 flex items-center gap-3 text-xs">
                                    <span class="rounded border border-cyan-200/20 bg-cyan-100/5 px-1.5 py-0.5 text-[10px] text-cyan-200/60">{{ $ent->entity_type }}</span>
                                    <span class="mono-ui text-cyan-200/70 flex-1">{{ Str::limit($ent->entity_key, 32) }}</span>
                                    <a href="{{ route('entity.show', $ent->id) }}" class="text-cyan-400 hover:text-cyan-200 transition">→</a>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif

    </div>
</x-app-layout>
