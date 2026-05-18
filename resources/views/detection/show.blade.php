<x-app-layout>
    <x-slot name="header">
        @php $db = $rule['db_state']; @endphp
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="brand-chip">Detection Rule</p>
                <h2 class="mt-2 text-xl font-semibold leading-tight text-main-ui">{{ $rule['title'] }}</h2>
                <p class="mono-ui mt-1 text-xs text-cyan-200/55">{{ $rule['rule_id'] }} · {{ $rule['domain'] }} · {{ $db->version }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span class="rounded border px-2 py-1 text-sm {{ $db->stageBadgeClass() }}">{{ $db->stage }}</span>
                @if ($db->suppression_active)
                    <span class="rounded border border-orange-400/40 bg-orange-500/10 px-2 py-1 text-xs text-orange-200">SUPPRESSED</span>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="flex gap-6">
        @include('detection._sidebar')

        <div class="min-w-0 flex-1 space-y-5">

            @if (session('status'))
                <div class="rounded-lg border border-green-400/30 bg-green-500/10 p-3 text-sm text-green-200">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-lg border border-red-400/40 bg-red-500/10 p-3 text-sm text-red-200">{{ $errors->first() }}</div>
            @endif

            {{-- Core metadata --}}
            <div class="glass-card p-5">
                <h3 class="mb-4 text-xs uppercase tracking-[0.12em] text-cyan-200/50">Rule Metadata</h3>
                <div class="grid grid-cols-2 gap-4 text-xs sm:grid-cols-4">
                    @foreach ([
                        'Domain'      => $rule['domain'],
                        'Severity'    => strtoupper($rule['severity'] ?? '—'),
                        'Confidence'  => number_format($rule['confidence'] ?? 0, 2),
                        'Version'     => $db->version,
                        'Owner'       => $db->owner ?? $rule['owner'] ?? '—',
                        'Output Topic'=> $rule['output_topic'] ?? '—',
                        'Shadow Only' => ($rule['shadow_only'] ?? false) ? 'yes' : 'no',
                        'Suppression Key' => $rule['suppression_key'] ?? '—',
                    ] as $label => $value)
                        <div>
                            <p class="text-cyan-200/40">{{ $label }}</p>
                            <p class="mono-ui mt-0.5 font-medium text-cyan-100">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4">
                    <p class="text-xs text-cyan-200/40">Description</p>
                    <p class="mt-1 text-sm leading-relaxed text-cyan-100/80">{{ $rule['description'] }}</p>
                </div>
            </div>

            {{-- False-positive notes --}}
            <div class="glass-card p-5">
                <h3 class="mb-3 text-xs uppercase tracking-[0.12em] text-cyan-200/50">False-Positive Notes</h3>
                <p class="text-sm leading-relaxed text-cyan-100/75">{{ $rule['false_positive_notes'] ?? '—' }}</p>

                @if ($rule['suppression_guidance'] ?? null)
                    <div class="mt-4 border-t border-cyan-100/10 pt-4">
                        <p class="mb-1 text-xs text-cyan-200/40">Suppression Guidance</p>
                        <p class="text-sm leading-relaxed text-cyan-100/70">{{ $rule['suppression_guidance'] }}</p>
                    </div>
                @endif

                @if ($rule['false_positive_rate'] ?? null)
                    <div class="mt-3">
                        <p class="text-xs text-cyan-200/40">Measured FP Rate</p>
                        <p class="mono-ui mt-0.5 text-sm font-medium text-cyan-100">{{ number_format($db->false_positive_rate * 100, 1) }}%</p>
                    </div>
                @endif
            </div>

            {{-- MITRE ATT&CK --}}
            <div class="glass-card p-5">
                <h3 class="mb-3 text-xs uppercase tracking-[0.12em] text-cyan-200/50">MITRE ATT&CK Coverage</h3>
                <div class="flex flex-wrap gap-2">
                    @forelse ($rule['mitre_attack'] ?? [] as $tech)
                        <span class="mono-ui rounded border border-purple-400/35 bg-purple-500/12 px-2.5 py-1 text-sm text-purple-200">{{ $tech }}</span>
                    @empty
                        <span class="text-xs text-cyan-200/35">No MITRE mapping declared</span>
                    @endforelse
                </div>
                @if (!empty($rule['event_types']))
                    <div class="mt-4">
                        <p class="mb-2 text-xs text-cyan-200/40">Event Types</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($rule['event_types'] as $et)
                                <span class="mono-ui rounded border border-cyan-200/20 bg-black/20 px-2 py-0.5 text-xs text-cyan-200/70">{{ $et }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Lifecycle & Validation --}}
            <div class="glass-card p-5">
                <h3 class="mb-4 text-xs uppercase tracking-[0.12em] text-cyan-200/50">Lifecycle &amp; Validation</h3>
                <div class="grid grid-cols-2 gap-4 text-xs sm:grid-cols-4">
                    <div>
                        <p class="text-cyan-200/40">Current Stage</p>
                        <p class="mt-0.5 font-semibold text-cyan-100">{{ $db->stage }}</p>
                    </div>
                    <div>
                        <p class="text-cyan-200/40">Replay Validated</p>
                        <p class="mt-0.5 {{ $db->replay_validated ? 'text-green-300' : 'text-yellow-300' }}">
                            {{ $db->replay_validated ? '✓ pass' : '✗ pending' }}
                            @if ($db->replay_validated_at)
                                <span class="text-cyan-200/35">({{ $db->replay_validated_at->format('Y-m-d') }})</span>
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-cyan-200/40">Promoted By</p>
                        <p class="mono-ui mt-0.5 text-cyan-100/80">{{ $db->promotion_approved_by ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-cyan-200/40">Promoted At</p>
                        <p class="mono-ui mt-0.5 text-cyan-100/80">{{ $db->promotion_approved_at?->format('Y-m-d') ?? '—' }}</p>
                    </div>
                </div>

                @if ($rule['validation_evidence'] ?? null)
                    <div class="mt-4">
                        <p class="text-xs text-cyan-200/40">Validation Evidence</p>
                        <p class="mono-ui mt-0.5 text-xs text-cyan-200/70">{{ $rule['validation_evidence'] }}</p>
                    </div>
                @endif

                @if ($rule['replay_fixture'] ?? null)
                    <div class="mt-3">
                        <p class="text-xs text-cyan-200/40">Replay Fixture</p>
                        <p class="mono-ui mt-0.5 text-xs text-cyan-200/55">{{ $rule['replay_fixture'] }}</p>
                        @if ($rule['expected_alert_count'] !== null)
                            <p class="mt-0.5 text-xs text-cyan-200/40">Expected alerts: <span class="text-cyan-200/70">{{ $rule['expected_alert_count'] }}</span></p>
                        @endif
                    </div>
                @endif

                {{-- Replay validation action --}}
                @if (!$db->replay_validated)
                    <div class="mt-4">
                        <form method="POST" action="{{ route('detection.replay.mark', $rule['rule_id']) }}">
                            @csrf
                            <button type="submit"
                                    class="rounded-lg border border-green-400/30 bg-green-500/10 px-3 py-1.5 text-xs text-green-200 hover:bg-green-500/20 transition">
                                ✓ Mark Replay Validated
                            </button>
                        </form>
                    </div>
                @endif
            </div>

            {{-- Promotion gates --}}
            @if (!empty($rule['gates']))
                @php
                    $nextStage = $db->nextStage();
                    $allGatesPassed = collect($rule['gates'])->every(fn ($g) => $g['passed']);
                @endphp
                <div class="glass-card p-5">
                    <h3 class="mb-1 text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        Promotion Gates — {{ $db->stage }} → {{ $nextStage }}
                    </h3>
                    @if (in_array($rule['domain'], ['endpoint', 'threat-intel']) && $nextStage === 'staged_active')
                        <p class="mb-3 text-xs text-orange-300/80">Endpoint and threat-intel domains are shadow-only. Promotion to staged_active is blocked pending domain soak approval.</p>
                    @endif
                    <div class="space-y-1.5 mt-3">
                        @foreach ($rule['gates'] as $gate)
                            <div class="flex items-center gap-2.5 text-xs">
                                <span class="{{ $gate['passed'] ? 'text-green-400' : 'text-red-400' }}">
                                    {{ $gate['passed'] ? '✓' : '✗' }}
                                </span>
                                <span class="{{ $gate['passed'] ? 'text-cyan-200/70' : 'text-red-200/70' }}">{{ $gate['label'] }}</span>
                                @if (!$gate['passed'] && ($gate['required'] ?? true))
                                    <span class="ml-auto rounded border border-red-400/25 bg-red-500/8 px-1.5 py-0.5 text-red-300/60">required</span>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if ($nextStage && !(in_array($rule['domain'], ['endpoint', 'threat-intel']) && $nextStage === 'staged_active'))
                        <form method="POST" action="{{ route('detection.promote', $rule['rule_id']) }}" class="mt-4">
                            @csrf
                            <input type="hidden" name="target_stage" value="{{ $nextStage }}">
                            <button type="submit"
                                    @if (!$allGatesPassed) disabled @endif
                                    class="rounded-lg border px-3 py-1.5 text-xs transition
                                        {{ $allGatesPassed
                                            ? 'border-green-400/40 bg-green-500/10 text-green-200 hover:bg-green-500/20'
                                            : 'border-cyan-200/15 bg-black/10 text-cyan-200/30 cursor-not-allowed' }}">
                                Promote to {{ $nextStage }}
                            </button>
                            @if (!$allGatesPassed)
                                <span class="ml-2 text-xs text-cyan-200/35">All required gates must pass before promotion.</span>
                            @endif
                        </form>
                    @endif
                </div>
            @endif

            {{-- Promotion checklist --}}
            @if (!empty($rule['checklist']))
                <div class="glass-card p-5">
                    <h3 class="mb-4 text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        Promotion Checklist — {{ count(array_filter($rule['checklist'], fn($i) => $i['checked'])) }}/{{ count($rule['checklist']) }} items checked
                    </h3>
                    <form method="POST" action="{{ route('detection.checklist.update', $rule['rule_id']) }}">
                        @csrf
                        <div class="space-y-2">
                            @foreach ($rule['checklist'] as $item)
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg px-2 py-1.5 hover:bg-cyan-100/3 transition">
                                    <input type="checkbox"
                                           name="checked[]"
                                           value="{{ $item['key'] }}"
                                           @checked($item['checked'])
                                           class="mt-0.5 h-3.5 w-3.5 shrink-0 rounded border-cyan-200/30 bg-black/20 text-cyan-400">
                                    <span class="text-xs {{ $item['checked'] ? 'text-cyan-200/55 line-through' : 'text-cyan-200/80' }}">{{ $item['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        <button type="submit" class="mt-4 rounded-lg border border-cyan-200/25 bg-cyan-100/5 px-3 py-1.5 text-xs text-cyan-200 hover:bg-cyan-100/10 transition">
                            Save Checklist
                        </button>
                    </form>
                </div>
            @endif

            {{-- Suppression management --}}
            <div class="glass-card p-5">
                <h3 class="mb-4 text-xs uppercase tracking-[0.12em] text-cyan-200/50">Suppression Policy</h3>
                @if ($db->suppression_active)
                    <div class="mb-4 rounded-lg border border-orange-400/30 bg-orange-500/10 p-3 text-xs text-orange-200">
                        <strong>Active suppression.</strong>
                        @if ($db->suppression_reason) {{ $db->suppression_reason }} @endif
                        @if ($db->suppression_expires_at)
                            Expires: {{ $db->suppression_expires_at->format('Y-m-d') }}.
                        @endif
                    </div>
                @endif
                <form method="POST" action="{{ route('detection.suppression.update', $rule['rule_id']) }}" class="space-y-3">
                    @csrf
                    <div class="flex items-center gap-3">
                        <label class="text-xs text-cyan-200/60">Suppression Active</label>
                        <select name="suppression_active" class="rounded-lg border border-cyan-200/20 bg-black/30 px-2 py-1 text-xs text-cyan-50 focus:outline-none">
                            <option value="0" @selected(!$db->suppression_active)>No</option>
                            <option value="1" @selected($db->suppression_active)>Yes</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-cyan-200/60">Reason / Scope</label>
                        <input type="text" name="suppression_reason" value="{{ $db->suppression_reason }}"
                               placeholder="e.g. actor=svc-account AND cloud_account=prod"
                               class="mt-1 w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-1.5 text-xs text-cyan-50 placeholder-cyan-200/25 focus:outline-none">
                    </div>
                    <div>
                        <label class="text-xs text-cyan-200/60">Expires At (required for staged_active)</label>
                        <input type="date" name="suppression_expires_at"
                               value="{{ $db->suppression_expires_at?->format('Y-m-d') }}"
                               class="mt-1 rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-1.5 text-xs text-cyan-50 focus:outline-none">
                    </div>
                    <button type="submit" class="rounded-lg border border-cyan-200/25 bg-cyan-100/5 px-3 py-1.5 text-xs text-cyan-200 hover:bg-cyan-100/10 transition">
                        Update Suppression
                    </button>
                </form>
            </div>

            {{-- Notes / FP history --}}
            <div class="glass-card p-5">
                <h3 class="mb-4 text-xs uppercase tracking-[0.12em] text-cyan-200/50">Notes &amp; False-Positive Log</h3>

                @forelse ($rule['notes'] as $note)
                    @php
                        $noteTypeColor = match($note->note_type) {
                            'false_positive' => 'border-orange-400/30 bg-orange-500/8 text-orange-200',
                            'promotion'      => 'border-green-400/30 bg-green-500/8 text-green-200',
                            'suppression'    => 'border-yellow-400/30 bg-yellow-500/8 text-yellow-200',
                            default          => 'border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70',
                        };
                    @endphp
                    <div class="mb-3 rounded-lg border border-cyan-100/10 bg-black/15 p-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="rounded border px-1.5 py-0.5 text-xs {{ $noteTypeColor }}">{{ $note->note_type }}</span>
                                <span class="text-xs text-cyan-200/45">{{ $note->user->name ?? 'Unknown' }}</span>
                            </div>
                            <span class="text-xs text-cyan-200/35">{{ $note->created_at->format('Y-m-d H:i') }}</span>
                        </div>
                        <p class="mt-2 text-xs leading-relaxed text-cyan-100/75">{{ $note->body }}</p>
                    </div>
                @empty
                    <p class="text-xs text-cyan-200/35">No notes yet.</p>
                @endforelse

                <form method="POST" action="{{ route('detection.notes.store', $rule['rule_id']) }}" class="mt-4 border-t border-cyan-100/10 pt-4 space-y-3">
                    @csrf
                    <div class="flex gap-3">
                        <select name="note_type" class="rounded-lg border border-cyan-200/20 bg-black/30 px-2 py-1.5 text-xs text-cyan-50 focus:outline-none">
                            <option value="general">General</option>
                            <option value="false_positive">False Positive</option>
                            <option value="promotion">Promotion</option>
                            <option value="suppression">Suppression</option>
                        </select>
                    </div>
                    <textarea name="body" rows="3" placeholder="Add a note about this rule…"
                              class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-xs text-cyan-50 placeholder-cyan-200/25 focus:outline-none resize-none"></textarea>
                    <button type="submit" class="rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-3 py-1.5 text-xs text-cyan-200 hover:bg-cyan-500/25 transition">
                        Add Note
                    </button>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
