<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="brand-chip">Investigation</p>
                <h2 class="mt-2 text-xl font-semibold leading-tight text-main-ui">
                    <span class="mono-ui text-cyan-200/60 text-base">{{ $investigation->investigation_id }}</span>
                    — {{ $investigation->title }}
                </h2>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('investigation.index') }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    ← Dashboard
                </a>
                <a href="{{ route('api.investigations.show', $investigation->id) }}" target="_blank" rel="noopener"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    JSON
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-5">

        {{-- Flash message --}}
        @if (session('success'))
            <div class="rounded-lg border border-green-400/30 bg-green-500/8 px-4 py-2.5 text-xs text-green-300">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-lg border border-red-400/30 bg-red-500/8 px-4 py-2.5 text-xs text-red-300">{{ $errors->first() }}</div>
        @endif

        {{-- Investigation header --}}
        @php
            $stateColor = match($investigation->state) {
                'new'              => 'border-cyan-400/60 bg-cyan-500/10 text-cyan-200',
                'triaged'          => 'border-blue-400/60 bg-blue-500/10 text-blue-200',
                'investigating'    => 'border-purple-400/60 bg-purple-500/10 text-purple-200',
                'escalated'        => 'border-red-400/60 bg-red-500/10 text-red-200',
                'contained_manual' => 'border-orange-400/60 bg-orange-500/10 text-orange-200',
                'resolved'         => 'border-green-400/60 bg-green-500/10 text-green-200',
                'false_positive'   => 'border-yellow-400/50 bg-yellow-500/8 text-yellow-200',
                default            => 'border-gray-400/40 bg-gray-500/10 text-gray-300',
            };
            $sevColor = match($investigation->severity) {
                'critical' => 'text-red-300',
                'high'     => 'text-orange-300',
                'medium'   => 'text-yellow-300',
                default    => 'text-cyan-200/60',
            };
        @endphp

        <div class="glass-card p-5">
            <div class="flex flex-wrap items-start gap-5">
                <div>
                    <p class="text-xs text-cyan-200/40 mb-1.5">State</p>
                    <span class="rounded-lg border-2 px-3 py-1.5 text-sm font-semibold {{ $stateColor }}">
                        {{ str_replace('_', ' ', strtoupper($investigation->state)) }}
                    </span>
                    @if ($investigation->state === 'contained_manual')
                        <p class="mt-1.5 text-[10px] text-orange-300/60">Documentation only — no automated action</p>
                    @endif
                </div>
                <div class="flex-1 min-w-0 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ([
                        ['label' => 'Severity', 'value' => strtoupper($investigation->severity), 'class' => $sevColor],
                        ['label' => 'Priority',  'value' => 'P' . $investigation->priority, 'class' => 'text-cyan-50 mono-ui'],
                        ['label' => 'Assignee',  'value' => $investigation->assignee_name ?? 'Unassigned', 'class' => 'text-cyan-200/70'],
                        ['label' => 'Created By','value' => $investigation->creator_name ?? '—', 'class' => 'text-cyan-200/60'],
                    ] as $m)
                        <div class="glass-card px-3 py-2.5">
                            <p class="text-xs text-cyan-200/40">{{ $m['label'] }}</p>
                            <p class="mt-0.5 text-sm font-semibold {{ $m['class'] }}">{{ $m['value'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            @if ($investigation->description)
                <p class="mt-4 text-sm text-cyan-200/60 border-t border-cyan-100/10 pt-4">{{ $investigation->description }}</p>
            @endif

            {{-- Trace / linked resources strip --}}
            <div class="mt-4 flex flex-wrap gap-3 text-xs">
                @if ($investigation->trace_id)
                    <span class="text-cyan-200/40">Trace:</span>
                    <a href="{{ route('traces.show', $investigation->trace_id) }}"
                       class="mono-ui text-cyan-400 hover:text-cyan-200 transition">{{ $investigation->trace_id }}</a>
                @endif
                @if ($linkedAlerts->isNotEmpty())
                    <span class="text-cyan-200/40">Alerts: {{ $linkedAlerts->count() }}</span>
                @endif
                @if ($linkedIncidents->isNotEmpty())
                    <span class="text-cyan-200/40">Incidents: {{ $linkedIncidents->count() }}</span>
                @endif
                @if ($linkedEntities->isNotEmpty())
                    <span class="text-cyan-200/40">Entities: {{ $linkedEntities->count() }}</span>
                @endif
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-3">

            {{-- State transition --}}
            @if (!empty($validTransitions) && $investigation->state !== 'closed')
                @can('soc:investigation.update')
                    <div class="glass-card p-4">
                        <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50 mb-3">Transition State</h3>
                        <form method="POST" action="{{ route('investigation.transition', $investigation->id) }}" class="space-y-2.5">
                            @csrf
                            <select name="state"
                                    class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                                @foreach ($validTransitions as $st)
                                    <option value="{{ $st }}">{{ str_replace('_', ' ', ucfirst($st)) }}</option>
                                @endforeach
                            </select>
                            <textarea name="note" rows="2"
                                      class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-1.5 text-xs text-cyan-50 focus:outline-none"
                                      placeholder="Optional transition note..."></textarea>
                            <button type="submit"
                                    class="w-full rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-3 py-2 text-sm text-cyan-200 hover:bg-cyan-500/25 transition">
                                Apply Transition
                            </button>
                        </form>
                    </div>
                @endcan
            @endif

            {{-- Assign analyst --}}
            @can('soc:investigation.assign')
                <div class="glass-card p-4">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50 mb-3">Assign Analyst</h3>
                    <form method="POST" action="{{ route('investigation.assign', $investigation->id) }}" class="space-y-2.5">
                        @csrf
                        <select name="assign_to"
                                class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                            @foreach ($users as $u)
                                <option value="{{ $u->id }}" @selected($investigation->assigned_to == $u->id)>
                                    {{ $u->name }} ({{ $u->role }})
                                </option>
                            @endforeach
                        </select>
                        <button type="submit"
                                class="w-full rounded-lg border border-blue-400/40 bg-blue-500/10 px-3 py-2 text-sm text-blue-200 hover:bg-blue-500/20 transition">
                            Assign
                        </button>
                    </form>
                </div>
            @endcan

            {{-- Add note --}}
            @can('soc:investigation.note')
                <div class="glass-card p-4">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50 mb-3">Add Note</h3>
                    <form method="POST" action="{{ route('investigation.notes.store', $investigation->id) }}" class="space-y-2.5">
                        @csrf
                        <select name="note_type"
                                class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                            @foreach (\App\Models\Investigation::NOTE_TYPES as $nt)
                                <option value="{{ $nt }}">{{ str_replace('_', ' ', ucfirst($nt)) }}</option>
                            @endforeach
                        </select>
                        <textarea name="body" rows="3" required
                                  class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none"
                                  placeholder="Note body..."></textarea>
                        <button type="submit"
                                class="w-full rounded-lg border border-green-400/40 bg-green-500/10 px-3 py-2 text-sm text-green-200 hover:bg-green-500/20 transition">
                            Add Note
                        </button>
                    </form>
                </div>
            @endcan

        </div>

        {{-- Timeline --}}
        <div class="glass-card overflow-hidden">
            <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                    Investigation Timeline ({{ $timeline->count() }} events)
                </h3>
                <a href="{{ route('api.investigations.timeline', $investigation->id) }}" target="_blank" rel="noopener"
                   class="text-xs text-cyan-400 hover:text-cyan-200 transition">JSON</a>
            </div>
            @if ($timeline->isEmpty())
                <div class="px-5 py-4 text-xs text-cyan-200/30">No events recorded.</div>
            @else
                <div class="px-5 py-4 space-y-3 max-h-80 overflow-y-auto">
                    @foreach ($timeline->sortByDesc('occurred_at') as $evt)
                        @php
                            $evtIcon = match($evt->event_type) {
                                'created'           => '✦',
                                'state_transition'  => '⟶',
                                'assignment'        => '👤',
                                'note_added'        => '📝',
                                'artifact_added'    => '📎',
                                default             => '●',
                            };
                            $payload = is_string($evt->payload) ? json_decode($evt->payload, true) : ($evt->payload ?? []);
                        @endphp
                        <div class="flex gap-3 text-xs">
                            <span class="shrink-0 text-cyan-400/60 w-4">{{ $evtIcon }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="mono-ui font-medium text-cyan-200/80">{{ $evt->event_type }}</span>
                                    @if ($evt->from_state && $evt->to_state)
                                        <span class="text-cyan-200/40">
                                            {{ $evt->from_state }} → {{ $evt->to_state }}
                                        </span>
                                    @endif
                                    @if ($evt->actor_name)
                                        <span class="text-cyan-200/40">by {{ $evt->actor_name }}</span>
                                    @endif
                                    <span class="ml-auto mono-ui text-cyan-200/30">
                                        {{ \Carbon\Carbon::parse($evt->occurred_at)->format('Y-m-d H:i:s') }}
                                    </span>
                                </div>
                                @if (!empty($payload['note']))
                                    <p class="mt-0.5 text-cyan-200/50 italic">"{{ Str::limit($payload['note'], 80) }}"</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="grid gap-5 lg:grid-cols-2">

            {{-- Notes panel --}}
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        Notes ({{ $notes->count() }})
                    </h3>
                </div>
                @if ($notes->isEmpty())
                    <div class="px-5 py-4 text-xs text-cyan-200/30">No notes added yet.</div>
                @else
                    <div class="divide-y divide-cyan-100/5 max-h-72 overflow-y-auto">
                        @foreach ($notes as $note)
                            <div class="px-5 py-3 {{ $note->is_pinned ? 'bg-cyan-500/5 border-l-2 border-cyan-400/40' : '' }}">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="rounded border border-cyan-200/20 bg-cyan-100/5 px-1.5 py-0.5 text-[10px] text-cyan-200/60">
                                        {{ $note->note_type }}
                                    </span>
                                    @if ($note->is_pinned)
                                        <span class="text-[10px] text-cyan-400/60">📌 pinned</span>
                                    @endif
                                    <span class="ml-auto text-[10px] text-cyan-200/30 mono-ui">
                                        {{ $note->author_name ?? 'system' }} · {{ \Carbon\Carbon::parse($note->created_at)->format('Y-m-d H:i') }}
                                    </span>
                                </div>
                                <p class="text-xs text-cyan-200/70 leading-relaxed">{{ $note->body }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Linked resources --}}
            <div class="space-y-3">

                @if ($linkedAlerts->isNotEmpty())
                    <div class="glass-card overflow-hidden">
                        <div class="border-b border-cyan-100/10 px-4 py-2.5">
                            <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Linked Alerts ({{ $linkedAlerts->count() }})</h3>
                        </div>
                        <div class="divide-y divide-cyan-100/5">
                            @foreach ($linkedAlerts->take(5) as $al)
                                <div class="flex items-center gap-3 px-4 py-2.5">
                                    @php $sev = $al->severity ?? 'medium';
                                    $ac = match($sev) { 'critical'=>'text-red-300','high'=>'text-orange-300','medium'=>'text-yellow-300',default=>'text-cyan-200/50' }; @endphp
                                    <span class="text-xs {{ $ac }} font-semibold">{{ strtoupper($sev) }}</span>
                                    <span class="mono-ui text-xs text-cyan-200/60 flex-1">{{ $al->alert_type ?? '—' }}</span>
                                    <span class="text-xs text-cyan-200/40">{{ isset($al->detected_at) ? \Carbon\Carbon::parse($al->detected_at)->format('H:i') : '' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($linkedEntities->isNotEmpty())
                    <div class="glass-card overflow-hidden">
                        <div class="border-b border-cyan-100/10 px-4 py-2.5">
                            <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Linked Entities ({{ $linkedEntities->count() }})</h3>
                        </div>
                        <div class="divide-y divide-cyan-100/5">
                            @foreach ($linkedEntities->take(5) as $ent)
                                <div class="flex items-center gap-3 px-4 py-2.5">
                                    <span class="rounded border border-cyan-200/20 bg-cyan-100/5 px-1.5 py-0.5 text-[10px] text-cyan-200/60">{{ $ent->entity_type }}</span>
                                    <span class="mono-ui text-xs text-cyan-200/70 flex-1">{{ Str::limit($ent->entity_key, 36) }}</span>
                                    <a href="{{ route('entity.show', $ent->id) }}" class="text-xs text-cyan-400 hover:text-cyan-200 transition">→</a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($artifacts->isNotEmpty())
                    <div class="glass-card overflow-hidden">
                        <div class="border-b border-cyan-100/10 px-4 py-2.5">
                            <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Artifacts ({{ $artifacts->count() }})</h3>
                        </div>
                        <div class="divide-y divide-cyan-100/5">
                            @foreach ($artifacts->take(5) as $art)
                                <div class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <span class="rounded border border-cyan-200/20 bg-cyan-100/5 px-1.5 py-0.5 text-[10px] text-cyan-200/50">{{ $art->artifact_type }}</span>
                                        <span class="text-xs text-cyan-200/70">{{ $art->title }}</span>
                                    </div>
                                    @if ($art->reference)
                                        <p class="mt-0.5 mono-ui text-[10px] text-cyan-200/40 truncate">{{ $art->reference }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

            </div>
        </div>

        {{-- Assignment history --}}
        @if ($assignments->isNotEmpty())
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        Assignment History ({{ $assignments->count() }})
                    </h3>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-2.5">Assignee</th>
                            <th class="px-4 py-2.5">Assigned By</th>
                            <th class="px-4 py-2.5">Assigned At</th>
                            <th class="px-4 py-2.5">Unassigned At</th>
                            <th class="px-4 py-2.5">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($assignments as $asgn)
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-2.5 text-xs text-cyan-200/75">{{ $asgn->assignee_name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/50">{{ $asgn->assigned_by_name ?? 'system' }}</td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/50 mono-ui">{{ \Carbon\Carbon::parse($asgn->assigned_at)->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/40 mono-ui">{{ $asgn->unassigned_at ? \Carbon\Carbon::parse($asgn->unassigned_at)->format('Y-m-d H:i') : '—' }}</td>
                                <td class="px-4 py-2.5">
                                    @if ($asgn->is_active)
                                        <span class="rounded border border-green-400/40 bg-green-500/10 px-1.5 py-0.5 text-[10px] text-green-300">active</span>
                                    @else
                                        <span class="rounded border border-cyan-200/20 bg-black/20 px-1.5 py-0.5 text-[10px] text-cyan-200/30">past</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif

    </div>
</x-app-layout>
