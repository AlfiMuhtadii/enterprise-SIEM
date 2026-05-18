<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">XDR Platform</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Investigation Dashboard</h2>
            </div>
            <div class="flex items-center gap-3">
                @can('soc:investigation.create')
                    <a href="#create-modal"
                       onclick="document.getElementById('create-panel').classList.toggle('hidden')"
                       class="rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-4 py-2 text-sm font-medium text-cyan-200 hover:bg-cyan-500/25 transition">
                        + New Investigation
                    </a>
                @endcan
                <a href="{{ route('investigation.queue') }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-2 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    My Queue
                </a>
                <a href="{{ route('investigation.escalations') }}"
                   class="rounded-lg border border-orange-400/30 bg-orange-500/8 px-3 py-2 text-sm text-orange-300/80 hover:text-orange-200 transition">
                    Escalations
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-5">

        {{-- State counts --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
            @foreach ([
                ['state' => 'new',              'color' => 'border-cyan-400/40 bg-cyan-500/10 text-cyan-300'],
                ['state' => 'triaged',          'color' => 'border-blue-400/40 bg-blue-500/10 text-blue-300'],
                ['state' => 'investigating',    'color' => 'border-purple-400/40 bg-purple-500/10 text-purple-300'],
                ['state' => 'escalated',        'color' => 'border-red-400/40 bg-red-500/10 text-red-300'],
                ['state' => 'contained_manual', 'color' => 'border-orange-400/40 bg-orange-500/10 text-orange-300'],
                ['state' => 'resolved',         'color' => 'border-green-400/40 bg-green-500/10 text-green-300'],
                ['state' => 'closed',           'color' => 'border-gray-400/30 bg-gray-500/10 text-gray-400'],
                ['state' => 'false_positive',   'color' => 'border-yellow-400/30 bg-yellow-500/8 text-yellow-400'],
            ] as $s)
                <a href="{{ route('investigation.index', ['state' => $s['state']]) }}"
                   class="glass-card px-3 py-2.5 border-l-2 {{ $s['color'] }} hover:opacity-80 transition {{ $stateFilter === $s['state'] ? 'ring-1 ring-inset ring-white/20' : '' }}">
                    <p class="text-[10px] uppercase tracking-[0.1em] opacity-60 truncate">{{ str_replace('_', ' ', $s['state']) }}</p>
                    <p class="mt-0.5 text-xl font-bold mono-ui">{{ (int) ($counts->get($s['state'])?->count ?? 0) }}</p>
                </a>
            @endforeach
        </div>

        {{-- Create Investigation Panel --}}
        @can('soc:investigation.create')
            <div id="create-panel" class="hidden glass-card p-5">
                <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50 mb-4">Create New Investigation</h3>
                <form method="POST" action="{{ route('investigation.store') }}" class="space-y-3">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs text-cyan-200/60">Title *</label>
                            <input type="text" name="title" required
                                   class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:border-cyan-400/40 focus:outline-none"
                                   placeholder="Brief investigation title"/>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs text-cyan-200/60">Severity *</label>
                                <select name="severity" class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                                    @foreach (['low','medium','high','critical'] as $sev)
                                        <option value="{{ $sev }}" @selected($sev === 'medium')>{{ ucfirst($sev) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-cyan-200/60">Priority</label>
                                <select name="priority" class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                                    @foreach ([1,2,3,4,5] as $p)
                                        <option value="{{ $p }}" @selected($p === 3)>P{{ $p }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-cyan-200/60">Description</label>
                        <textarea name="description" rows="2"
                                  class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:border-cyan-400/40 focus:outline-none"
                                  placeholder="Optional description..."></textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-cyan-200/60">Linked Trace ID (optional)</label>
                        <input type="text" name="trace_id"
                               class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:border-cyan-400/40 focus:outline-none mono-ui"
                               placeholder="e.g. 3a7f1c2e-..."/>
                    </div>
                    @if ($errors->any())
                        <p class="text-xs text-red-400">{{ $errors->first() }}</p>
                    @endif
                    <div class="flex gap-2">
                        <button type="submit"
                                class="rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-5 py-2 text-sm font-medium text-cyan-200 hover:bg-cyan-500/25 transition">
                            Create
                        </button>
                        <button type="button" onclick="document.getElementById('create-panel').classList.add('hidden')"
                                class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-4 py-2 text-sm text-cyan-200/60 hover:text-cyan-200 transition">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        @endcan

        @if (session('success'))
            <div class="rounded-lg border border-green-400/30 bg-green-500/8 px-4 py-2.5 text-xs text-green-300">
                {{ session('success') }}
            </div>
        @endif

        {{-- Filter bar --}}
        <div class="glass-card p-4">
            <form method="GET" action="{{ route('investigation.index') }}" class="flex flex-wrap gap-3 items-end">
                <div class="w-36">
                    <label class="mb-1 block text-xs text-cyan-200/60">State</label>
                    <select name="state" class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                        <option value="">All (open)</option>
                        @foreach (\App\Models\Investigation::VALID_STATES as $st)
                            <option value="{{ $st }}" @selected($stateFilter === $st)>{{ str_replace('_', ' ', ucfirst($st)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-32">
                    <label class="mb-1 block text-xs text-cyan-200/60">Severity</label>
                    <select name="severity" class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                        <option value="">All</option>
                        @foreach (['low','medium','high','critical'] as $sev)
                            <option value="{{ $sev }}" @selected($severityFilter === $sev)>{{ ucfirst($sev) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-36">
                    <label class="mb-1 block text-xs text-cyan-200/60">Assigned</label>
                    <select name="assigned" class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                        <option value="">Anyone</option>
                        <option value="me" @selected($assignFilter === 'me')>Me</option>
                        <option value="unassigned" @selected($assignFilter === 'unassigned')>Unassigned</option>
                    </select>
                </div>
                <button type="submit"
                        class="rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-500/25 transition">
                    Filter
                </button>
                @if ($stateFilter || $severityFilter || $assignFilter)
                    <a href="{{ route('investigation.index') }}"
                       class="text-xs text-cyan-200/40 hover:text-cyan-200 transition self-end pb-2">
                        Clear
                    </a>
                @endif
            </form>
        </div>

        {{-- Investigations table --}}
        @if ($investigations->isEmpty())
            <div class="glass-card p-8 text-center text-sm text-cyan-200/40">
                No investigations match the current filters.
            </div>
        @else
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        {{ $investigations->count() }} investigation{{ $investigations->count() === 1 ? '' : 's' }}
                    </span>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-3">ID</th>
                            <th class="px-4 py-3">Title</th>
                            <th class="px-4 py-3">State</th>
                            <th class="px-4 py-3">Severity</th>
                            <th class="px-4 py-3">P</th>
                            <th class="px-4 py-3">Assignee</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($investigations as $inv)
                            @php
                                $stateColor = match($inv->state) {
                                    'new'              => 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10',
                                    'triaged'          => 'text-blue-300 border-blue-400/40 bg-blue-500/10',
                                    'investigating'    => 'text-purple-300 border-purple-400/40 bg-purple-500/10',
                                    'escalated'        => 'text-red-300 border-red-400/40 bg-red-500/10',
                                    'contained_manual' => 'text-orange-300 border-orange-400/40 bg-orange-500/10',
                                    'resolved'         => 'text-green-300 border-green-400/40 bg-green-500/10',
                                    'false_positive'   => 'text-yellow-300 border-yellow-400/30 bg-yellow-500/8',
                                    default            => 'text-gray-400 border-gray-400/30 bg-gray-500/10',
                                };
                                $sevColor = match($inv->severity) {
                                    'critical' => 'text-red-300',
                                    'high'     => 'text-orange-300',
                                    'medium'   => 'text-yellow-300',
                                    default    => 'text-cyan-200/50',
                                };
                            @endphp
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-3">
                                    <span class="mono-ui text-xs text-cyan-200/60">{{ $inv->investigation_id }}</span>
                                </td>
                                <td class="px-4 py-3 max-w-xs">
                                    <span class="text-sm text-cyan-50">{{ Str::limit($inv->title, 44) }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded border px-1.5 py-0.5 text-xs {{ $stateColor }}">
                                        {{ str_replace('_', ' ', $inv->state) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs font-semibold {{ $sevColor }}">
                                    {{ strtoupper($inv->severity) }}
                                </td>
                                <td class="px-4 py-3 text-xs text-cyan-200/50 mono-ui">P{{ $inv->priority }}</td>
                                <td class="px-4 py-3 text-xs text-cyan-200/60">
                                    {{ $inv->assignee_name ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-xs text-cyan-200/45">
                                    {{ \Carbon\Carbon::parse($inv->created_at)->format('Y-m-d H:i') }}
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('investigation.show', $inv->id) }}"
                                       class="text-xs text-cyan-400 hover:text-cyan-200 transition">
                                        Open →
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    </div>
</x-app-layout>
