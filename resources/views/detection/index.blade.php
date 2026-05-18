<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">XDR Platform</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Detection Rule Registry</h2>
            </div>
            <span class="text-xs uppercase tracking-[0.14em] text-cyan-100/50">{{ $stats['total'] }} rules · registry v1</span>
        </div>
    </x-slot>

    <div class="flex gap-6">
        @include('detection._sidebar')

        <div class="min-w-0 flex-1 space-y-5">

            {{-- Stats --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ([
                    ['label' => 'Total Rules',     'value' => $stats['total'],         'color' => 'text-cyan-50'],
                    ['label' => 'Staged Active',   'value' => $stats['staged_active'], 'color' => 'text-green-300'],
                    ['label' => 'Shadow',          'value' => $stats['shadow'],        'color' => 'text-yellow-300'],
                    ['label' => 'Draft',           'value' => $stats['draft'],         'color' => 'text-cyan-300'],
                    ['label' => 'Deprecated',      'value' => $stats['deprecated'],    'color' => 'text-red-300/60'],
                    ['label' => 'MITRE Techniques','value' => $stats['mitre_count'],   'color' => 'text-purple-300'],
                ] as $m)
                    <div class="glass-card px-4 py-3">
                        <p class="text-xs text-cyan-200/45">{{ $m['label'] }}</p>
                        <p class="mt-1 text-xl font-semibold {{ $m['color'] }}">{{ $m['value'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Filters --}}
            <div class="glass-card p-4">
                <form method="GET" action="{{ route('detection.index') }}" class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs text-cyan-200/55">Domain</label>
                        <select name="domain" class="rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-1.5 text-sm text-cyan-50 focus:outline-none">
                            <option value="">All domains</option>
                            @foreach ($domains as $d)
                                <option value="{{ $d }}" @selected($domain === $d)>{{ $d }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-cyan-200/55">Stage</label>
                        <select name="stage" class="rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-1.5 text-sm text-cyan-50 focus:outline-none">
                            <option value="">All stages</option>
                            @foreach ($stages as $s)
                                <option value="{{ $s }}" @selected($stage === $s)>{{ $s }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-4 py-1.5 text-sm text-cyan-200 hover:bg-cyan-500/25 transition">Filter</button>
                    @if ($domain || $stage)
                        <a href="{{ route('detection.index') }}" class="text-xs text-cyan-200/50 hover:text-cyan-200 transition self-center">Clear</a>
                    @endif
                </form>
            </div>

            {{-- Rule table --}}
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">{{ $rules->count() }} rules</span>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.08em] text-cyan-200/35">
                            <th class="px-5 py-3">Rule ID</th>
                            <th class="px-4 py-3">Domain</th>
                            <th class="px-4 py-3">Stage</th>
                            <th class="px-4 py-3">Version</th>
                            <th class="px-4 py-3">Confidence</th>
                            <th class="px-4 py-3">MITRE</th>
                            <th class="px-4 py-3">Replay</th>
                            <th class="px-4 py-3">Owner</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($rules as $rule)
                            @php
                                $db = $rule['db_state'];
                                $stageColor = match($db->stage) {
                                    'staged_active' => 'border-green-400/40 bg-green-500/10 text-green-200',
                                    'shadow'        => 'border-yellow-400/40 bg-yellow-500/10 text-yellow-200',
                                    'draft'         => 'border-cyan-400/40 bg-cyan-500/10 text-cyan-200',
                                    'deprecated'    => 'border-red-400/30 bg-red-500/10 text-red-200/60',
                                    default         => 'border-cyan-200/20 bg-black/20 text-cyan-100/50',
                                };
                                $domainColor = match($rule['domain']) {
                                    'identity'     => 'text-cyan-300',
                                    'cloud'        => 'text-blue-300',
                                    'saas'         => 'text-purple-300',
                                    'endpoint'     => 'text-orange-300',
                                    'threat-intel' => 'text-red-300',
                                    default        => 'text-cyan-200/55',
                                };
                            @endphp
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-3">
                                    <span class="mono-ui text-xs text-cyan-200/80">{{ $rule['rule_id'] }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs font-medium {{ $domainColor }}">{{ $rule['domain'] }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded border px-1.5 py-0.5 text-xs {{ $stageColor }}">{{ $db->stage }}</span>
                                </td>
                                <td class="px-4 py-3 mono-ui text-xs text-cyan-200/55">{{ $db->version }}</td>
                                <td class="px-4 py-3 text-xs text-cyan-200/70">{{ number_format($rule['confidence'] ?? 0, 2) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach (array_slice($rule['mitre_attack'] ?? [], 0, 2) as $tech)
                                            <span class="mono-ui rounded border border-purple-400/30 bg-purple-500/10 px-1.5 py-0.5 text-xs text-purple-200">{{ $tech }}</span>
                                        @endforeach
                                        @if (count($rule['mitre_attack'] ?? []) > 2)
                                            <span class="text-xs text-cyan-200/35">+{{ count($rule['mitre_attack']) - 2 }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($db->replay_validated)
                                        <span class="text-xs text-green-300">✓ pass</span>
                                    @else
                                        <span class="text-xs text-cyan-200/35">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-cyan-200/55">{{ $db->owner ?? $rule['owner'] ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('detection.show', $rule['rule_id']) }}"
                                       class="text-xs text-cyan-400 hover:text-cyan-200 transition">Details →</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- MITRE ATT&CK Coverage --}}
            <div id="mitre" class="glass-card p-5">
                <h3 class="mb-4 text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                    MITRE ATT&CK Coverage — {{ count($mitre) }} techniques
                </h3>
                <div class="flex flex-wrap gap-2">
                    @foreach ($mitre as $entry)
                        @php
                            $count   = count($entry['rules']);
                            $domains = implode(', ', $entry['domains']);
                            $opacity = $count >= 3 ? 'bg-purple-500/25 border-purple-400/50 text-purple-100' : ($count === 2 ? 'bg-purple-500/15 border-purple-400/35 text-purple-200' : 'bg-purple-500/8 border-purple-400/20 text-purple-300');
                        @endphp
                        <span class="mono-ui rounded border px-2.5 py-1 text-xs {{ $opacity }}"
                              title="{{ $domains }} ({{ $count }} rule{{ $count !== 1 ? 's' : '' }})">
                            {{ $entry['technique'] }}
                            <span class="ml-1 text-xs opacity-60">×{{ $count }}</span>
                        </span>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-cyan-200/30">Hover for domain and rule count. Darker = more rules covering this technique.</p>
            </div>

        </div>
    </div>
</x-app-layout>
