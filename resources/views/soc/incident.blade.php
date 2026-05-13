<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="brand-chip">Incident Detail</p>
                <h2 class="mt-2 text-2xl font-semibold text-main-ui">{{ $incident->incident_id }}</h2>
                <p class="mt-1 text-sm text-muted-ui">{{ $incident->title }}</p>
            </div>
            <a href="{{ route('soc.dashboard') }}" class="rounded-lg border border-cyan-200/30 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Back to SOC</a>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-cyan-200/20 bg-cyan-100/10 p-3 text-sm text-cyan-50">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 lg:grid-cols-4">
        <div class="metric-card"><p class="text-sm text-cyan-200/75">Severity</p><p class="mt-2 text-2xl text-main-ui">{{ $incident->severity }}</p></div>
        <div class="metric-card"><p class="text-sm text-cyan-200/75">Confidence</p><p class="mt-2 text-2xl text-main-ui">{{ number_format((float) $incident->confidence, 3) }}</p></div>
        <div class="metric-card"><p class="text-sm text-cyan-200/75">Status</p><p class="mt-2 text-2xl text-main-ui">{{ $incident->status }}</p></div>
        <div class="metric-card"><p class="text-sm text-cyan-200/75">Analyst</p><p class="mt-2 text-2xl text-main-ui">{{ $incident->assigned_analyst ?: '-' }}</p></div>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-3">
        <section class="glass-card p-5 xl:col-span-2">
            <h3 class="text-lg font-semibold text-main-ui">Workflow Update</h3>
            @if (in_array(Auth::user()?->role, ['admin', 'analyst'], true))
                <form method="POST" action="{{ route('soc.incidents.update', $incident->incident_id) }}" class="mt-4 grid gap-3 md:grid-cols-2">
                    @csrf
                    <select name="status" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                        @foreach (['open','triaged','investigating','resolved','false_positive'] as $s)
                            <option value="{{ $s }}" @selected($incident->status === $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                    <select name="severity" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                        @foreach (['critical','high','medium','low'] as $s)
                            <option value="{{ $s }}" @selected($incident->severity === $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                    <input name="assigned_analyst" value="{{ $incident->assigned_analyst }}" placeholder="Analyst" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                    <select name="action" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                        @foreach (['update','triage','investigate','escalate','resolve','false_positive'] as $a)
                            <option value="{{ $a }}">{{ $a }}</option>
                        @endforeach
                    </select>
                    <textarea name="note" placeholder="Analyst note" class="md:col-span-2 rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50"></textarea>
                    <textarea name="resolution_summary" placeholder="Resolution summary" class="md:col-span-2 rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">{{ $incident->resolution_summary }}</textarea>
                    <button class="md:col-span-2 rounded-lg border border-cyan-200/30 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Save Workflow</button>
                </form>
            @else
                <p class="mt-4 text-sm text-muted-ui">Viewer role can inspect incidents but cannot update workflow.</p>
            @endif
        </section>
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Entities & MITRE</h3>
            <div class="mt-4 space-y-2">
                @foreach ($affectedEntities as $entity)
                    <div class="rounded bg-black/20 p-2 mono-ui text-xs text-cyan-50">{{ $entity }}</div>
                @endforeach
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($mitreMapping as $technique)
                    <span class="rounded-full bg-amber-300/10 px-2 py-1 mono-ui text-xs text-amber-100">{{ $technique }}</span>
                @endforeach
            </div>
        </section>
    </div>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">AI-Assisted Analyst</h3></div>
        <div class="grid gap-4 p-5 lg:grid-cols-3">
            @if (in_array(Auth::user()?->role, ['admin', 'analyst'], true))
                <form method="POST" action="{{ route('soc.ai.generate', $incident->incident_id) }}" class="rounded-lg border border-cyan-200/15 bg-black/20 p-4">
                    @csrf
                    <h4 class="text-sm font-semibold text-cyan-50">Generate Assistance</h4>
                    <select name="suggestion_type" class="mt-3 w-full rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                        @foreach (['incident_summary','evidence_explanation','alert_context','investigation_steps','response_actions','playbook_suggestion','executive_narrative','analyst_assist'] as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                    <button class="mt-3 rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Generate</button>
                </form>
            @endif
            <div class="space-y-3 lg:col-span-2">
                @forelse ($aiSuggestions as $suggestion)
                    @php $ai = json_decode($suggestion->output ?: '{}', true) ?: []; @endphp
                    <details class="rounded-lg border border-cyan-200/15 bg-black/20 p-4" open>
                        <summary class="cursor-pointer"><span class="mono-ui text-cyan-50">{{ $suggestion->suggestion_type }}</span> <span class="text-cyan-100/70">{{ $suggestion->status }} | {{ $suggestion->provider }} | {{ $suggestion->model ?: '-' }} | {{ $suggestion->confidence_label ?? 'medium' }}</span></summary>
                        <p class="mt-3 text-sm text-cyan-100">{{ $ai['summary'] ?? '-' }}</p>
                        <p class="mt-2 text-xs text-amber-100">{{ $ai['hallucination_warning'] ?? 'Validate AI output against source evidence.' }} Guardrail={{ $suggestion->guardrail_status ?? 'passed' }} latency={{ $suggestion->latency_ms ?? '-' }}ms</p>
                        <div class="mt-3 grid gap-2 md:grid-cols-2">
                            <div><p class="text-xs uppercase tracking-[0.12em] text-cyan-100/50">Recommended Steps</p><ul class="mt-1 list-disc pl-5 text-xs text-cyan-100">@foreach (($ai['recommended_steps'] ?? []) as $step)<li>{{ $step }}</li>@endforeach</ul></div>
                            <div><p class="text-xs uppercase tracking-[0.12em] text-cyan-100/50">Responses</p><ul class="mt-1 list-disc pl-5 text-xs text-cyan-100">@foreach (($ai['recommended_responses'] ?? []) as $step)<li>{{ $step }}</li>@endforeach</ul></div>
                        </div>
                        <div class="mt-3">
                            <p class="text-xs uppercase tracking-[0.12em] text-cyan-100/50">Retrieval Citations</p>
                            <div class="mt-2 grid gap-2 md:grid-cols-2">
                                @forelse (($ai['retrieval_citations'] ?? []) as $citation)
                                    <div class="rounded border border-cyan-200/10 bg-slate-950/50 p-2 text-xs text-cyan-100">{{ $citation['kb_id'] ?? '-' }} | {{ $citation['title'] ?? '-' }}<p class="text-cyan-100/50">{{ $citation['entry_type'] ?? '-' }} score={{ $citation['score'] ?? '-' }}</p></div>
                                @empty
                                    <p class="text-xs text-muted-ui">No retrieval citation attached.</p>
                                @endforelse
                            </div>
                        </div>
                        @if (in_array(Auth::user()?->role, ['admin', 'analyst'], true) && $suggestion->status === 'pending_review')
                            <form method="POST" action="{{ route('soc.ai.review', $suggestion->suggestion_id) }}" class="mt-3 flex gap-2">
                                @csrf
                                <input name="review_note" placeholder="review note" class="min-w-0 flex-1 rounded border border-cyan-200/30 bg-slate-950 px-2 py-1 text-xs text-cyan-50">
                                <button name="status" value="accepted" class="rounded border border-emerald-200/30 px-2 py-1 text-xs text-emerald-100">Accept</button>
                                <button name="status" value="rejected" class="rounded border border-red-200/30 px-2 py-1 text-xs text-red-100">Reject</button>
                            </form>
                        @endif
                    </details>
                @empty
                    <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-4 text-sm text-muted-ui">No AI suggestions generated for this incident.</div>
                @endforelse
            </div>
        </div>
    </section>

    <section class="glass-card mt-4 p-5">
        <h3 class="text-lg font-semibold text-main-ui">Related Knowledge</h3>
        <div class="mt-3 grid gap-3 md:grid-cols-2">
            @forelse ($knowledgeRefs as $entry)
                <div class="rounded border border-cyan-200/15 bg-black/20 p-3"><p class="text-sm text-cyan-50">{{ $entry->title }}</p><p class="text-xs text-cyan-100/60">{{ $entry->entry_type }} | {{ $entry->updated_at }}</p></div>
            @empty
                <p class="text-sm text-muted-ui">No related knowledge entries.</p>
            @endforelse
        </div>
    </section>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Investigation Timeline</h3></div>
        <div class="grid gap-3 p-5 lg:grid-cols-2">
            @foreach ($timeline as $item)
                <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                    <p class="mono-ui text-xs text-cyan-100/80">{{ $item['ts'] ?? '-' }}</p>
                    <p class="mt-1 text-sm text-cyan-50">{{ $item['alert_type'] ?? 'event' }}</p>
                    <p class="mono-ui mt-1 text-xs text-cyan-100/70">{{ $item['alert_id'] ?? '' }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Investigation Playbooks</h3></div>
        <div class="p-5">
            @if (in_array(Auth::user()?->role, ['admin', 'analyst'], true))
                <form method="POST" action="{{ route('soc.playbooks.store', $incident->incident_id) }}" class="mb-4 grid gap-3 md:grid-cols-5">
                    @csrf
                    <input name="name" value="{{ $incident->title }} Playbook" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                    <input name="incident_type" value="{{ $incident->severity }}" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                    <input name="assigned_analyst" value="{{ Auth::user()?->email }}" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                    <select name="template" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                        <option value="generic">generic</option>
                        <option value="web_attack">web_attack</option>
                        <option value="endpoint_compromise">endpoint_compromise</option>
                    </select>
                    <button class="rounded-lg border border-cyan-200/30 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Create Playbook</button>
                </form>
            @endif
            <div class="space-y-4">
                @forelse ($playbooks as $playbook)
                    <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div><p class="mono-ui text-cyan-50">{{ $playbook->playbook_id }}</p><p class="text-sm text-cyan-100">{{ $playbook->name }} | {{ $playbook->status }} | {{ $playbook->progress_percent }}%</p></div>
                            <p class="text-xs text-cyan-100/60">{{ $playbook->assigned_analyst ?: '-' }}</p>
                        </div>
                        <div class="mt-3 space-y-2">
                            @foreach (($playbookTasks[$playbook->playbook_id] ?? collect()) as $task)
                                <form method="POST" action="{{ route('soc.playbooks.tasks.update', $task->task_id) }}" class="grid gap-2 rounded border border-cyan-200/10 bg-slate-950/40 p-3 md:grid-cols-5">
                                    @csrf
                                    <div class="md:col-span-2"><p class="text-sm text-cyan-50">{{ $task->title }}</p><p class="text-xs text-cyan-100/50">{{ $task->task_type }}</p></div>
                                    <select name="status" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-1 text-xs text-cyan-50">
                                        @foreach (['pending','in_progress','blocked','completed','skipped'] as $s)
                                            <option value="{{ $s }}" @selected($task->status === $s)>{{ $s }}</option>
                                        @endforeach
                                    </select>
                                    <input name="assigned_to" value="{{ $task->assigned_to }}" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-1 text-xs text-cyan-50">
                                    <button class="rounded border border-cyan-200/20 px-2 py-1 text-xs text-cyan-50">Update</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-muted-ui">No playbook assigned yet.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Related Alerts & Evidence Chain</h3></div>
        <div class="grid gap-3 p-5">
            @foreach ($alerts as $alert)
                @php $evidence = json_decode($alert->evidence ?: '{}', true) ?: []; @endphp
                <details class="rounded-lg border border-cyan-200/15 bg-black/20 p-4">
                    <summary class="cursor-pointer"><span class="mono-ui text-cyan-50">{{ $alert->alert_type }}</span> <span class="text-cyan-100/70">{{ $alert->severity }} | {{ $alert->detected_at }}</span></summary>
                    <pre class="mt-3 max-h-96 overflow-auto rounded bg-slate-950/70 p-3 text-xs text-cyan-50">{{ json_encode($evidence['evidence_chain'] ?? $evidence, JSON_PRETTY_PRINT) }}</pre>
                </details>
            @endforeach
        </div>
    </section>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <section class="glass-card p-5"><h3 class="text-lg font-semibold text-main-ui">Analyst Notes</h3><div class="mt-4 space-y-3">@foreach ($notes as $note)<div class="rounded bg-black/20 p-3"><p class="text-sm text-cyan-50">{{ $note->body }}</p><p class="mt-1 text-xs text-cyan-100/60">{{ $note->author }} | {{ $note->note_type }} | {{ $note->created_at }}</p></div>@endforeach</div></section>
        <section class="glass-card p-5"><h3 class="text-lg font-semibold text-main-ui">Workflow History</h3><div class="mt-4 space-y-3">@foreach ($activities as $activity)<div class="rounded bg-black/20 p-3"><p class="text-sm text-cyan-50">{{ $activity->activity_type }}</p><p class="mt-1 text-xs text-cyan-100/60">{{ $activity->actor }} | {{ $activity->created_at }}</p></div>@endforeach</div></section>
        <section class="glass-card p-5"><h3 class="text-lg font-semibold text-main-ui">Audit Trail</h3><div class="mt-4 space-y-3">@foreach ($audit as $row)<div class="rounded bg-black/20 p-3"><p class="text-sm text-cyan-50">{{ $row->action }}</p><p class="mt-1 text-xs text-cyan-100/60">{{ $row->actor }} | {{ $row->occurred_at }}</p></div>@endforeach</div></section>
    </div>
</x-app-layout>
