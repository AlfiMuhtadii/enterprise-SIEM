<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">XDR Platform</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Response Planning</h2>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-cyan-100/40 uppercase tracking-[0.12em]">Advisory Only — No Automated Execution</span>
                @can('soc:response.create')
                    <a href="#create-panel"
                       onclick="document.getElementById('create-panel').classList.toggle('hidden')"
                       class="rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-4 py-2 text-sm font-medium text-cyan-200 hover:bg-cyan-500/25 transition">
                        + New Plan
                    </a>
                @endcan
                <a href="{{ route('response.recommendations') }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-2 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    Recommendations
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-5">

        {{-- Advisory notice --}}
        <div class="rounded-lg border border-yellow-400/20 bg-yellow-500/5 px-4 py-3 text-xs text-yellow-200/70">
            Response plans are analyst-facing planning documents only.
            No actions are executed automatically. <strong>completed_documented</strong> = analyst documented manual external action.
            Shadow-domain actions are marked <span class="mono-ui">advisory_only</span>.
        </div>

        {{-- State counts --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ([
                ['state' => 'draft',               'color' => 'border-gray-400/40 bg-gray-500/10 text-gray-300'],
                ['state' => 'pending_approval',    'color' => 'border-yellow-400/40 bg-yellow-500/10 text-yellow-300'],
                ['state' => 'approved',            'color' => 'border-green-400/40 bg-green-500/10 text-green-300'],
                ['state' => 'rejected',            'color' => 'border-red-400/40 bg-red-500/10 text-red-300'],
                ['state' => 'completed_documented','color' => 'border-cyan-400/40 bg-cyan-500/10 text-cyan-300'],
                ['state' => 'cancelled',           'color' => 'border-gray-400/20 bg-gray-500/5 text-gray-400'],
            ] as $s)
                <a href="{{ route('response.index', ['state' => $s['state']]) }}"
                   class="glass-card px-3 py-2.5 border-l-2 {{ $s['color'] }} hover:opacity-80 transition {{ $stateFilter === $s['state'] ? 'ring-1 ring-inset ring-white/20' : '' }}">
                    <p class="text-[10px] uppercase tracking-[0.1em] opacity-60 truncate">{{ str_replace('_', ' ', $s['state']) }}</p>
                    <p class="mt-0.5 text-xl font-bold mono-ui">{{ (int) ($counts->get($s['state'])?->count ?? 0) }}</p>
                </a>
            @endforeach
        </div>

        @if ($pendingCount > 0)
            <div class="rounded-lg border border-yellow-400/30 bg-yellow-500/8 px-4 py-3 flex items-center justify-between">
                <span class="text-xs text-yellow-200/80">
                    {{ $pendingCount }} plan{{ $pendingCount === 1 ? '' : 's' }} awaiting your approval
                </span>
                <a href="{{ route('response.index', ['state' => 'pending_approval']) }}"
                   class="text-xs text-yellow-300 hover:text-yellow-100 transition">Review →</a>
            </div>
        @endif

        {{-- Create plan panel --}}
        @can('soc:response.create')
            <div id="create-panel" class="hidden glass-card p-5">
                <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50 mb-4">New Response Plan</h3>
                <form method="POST" action="{{ route('response.store') }}" class="space-y-3">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs text-cyan-200/60">Title *</label>
                            <input type="text" name="title" required
                                   class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:border-cyan-400/40 focus:outline-none"
                                   placeholder="e.g. Response to MFA burst on admin user"/>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs text-cyan-200/60">Risk Level</label>
                                <select name="risk_level" class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                                    <option value="">— none —</option>
                                    @foreach (['low','medium','high','critical'] as $l)
                                        <option value="{{ $l }}">{{ ucfirst($l) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-cyan-200/60">Trace ID</label>
                                <input type="text" name="trace_id"
                                       class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 mono-ui focus:border-cyan-400/40 focus:outline-none"
                                       placeholder="optional"/>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-cyan-200/60">Description</label>
                        <textarea name="description" rows="2"
                                  class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none"
                                  placeholder="Optional context..."></textarea>
                    </div>
                    @if ($errors->any())
                        <p class="text-xs text-red-400">{{ $errors->first() }}</p>
                    @endif
                    <div class="flex gap-2">
                        <button type="submit"
                                class="rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-5 py-2 text-sm text-cyan-200 hover:bg-cyan-500/25 transition">
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
            <div class="rounded-lg border border-green-400/30 bg-green-500/8 px-4 py-2.5 text-xs text-green-300">{{ session('success') }}</div>
        @endif

        {{-- Plans table --}}
        @if ($plans->isEmpty())
            <div class="glass-card p-8 text-center text-sm text-cyan-200/40">No response plans match the current filter.</div>
        @else
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        {{ $plans->count() }} plan{{ $plans->count() === 1 ? '' : 's' }}
                    </span>
                    @if ($stateFilter)
                        <a href="{{ route('response.index') }}" class="text-xs text-cyan-200/40 hover:text-cyan-200 transition">Clear filter</a>
                    @endif
                </div>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-3">ID</th>
                            <th class="px-4 py-3">Title</th>
                            <th class="px-4 py-3">State</th>
                            <th class="px-4 py-3">Risk</th>
                            <th class="px-4 py-3">Creator</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($plans as $plan)
                            @php
                                $sc = match($plan->state) {
                                    'draft'               => 'text-gray-300 border-gray-400/40 bg-gray-500/10',
                                    'pending_approval'    => 'text-yellow-300 border-yellow-400/40 bg-yellow-500/10',
                                    'approved'            => 'text-green-300 border-green-400/40 bg-green-500/10',
                                    'rejected'            => 'text-red-300 border-red-400/40 bg-red-500/10',
                                    'completed_documented'=> 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10',
                                    default               => 'text-gray-400 border-gray-400/20 bg-black/20',
                                };
                                $rc = match($plan->risk_level) {
                                    'critical' => 'text-red-300',
                                    'high'     => 'text-orange-300',
                                    'medium'   => 'text-yellow-300',
                                    'low'      => 'text-green-300',
                                    default    => 'text-cyan-200/40',
                                };
                            @endphp
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-3"><span class="mono-ui text-xs text-cyan-200/60">{{ $plan->plan_id }}</span></td>
                                <td class="px-4 py-3 max-w-xs"><span class="text-sm text-cyan-50">{{ Str::limit($plan->title, 44) }}</span></td>
                                <td class="px-4 py-3"><span class="rounded border px-1.5 py-0.5 text-xs {{ $sc }}">{{ str_replace('_',' ',$plan->state) }}</span></td>
                                <td class="px-4 py-3 text-xs font-semibold {{ $rc }}">{{ strtoupper($plan->risk_level ?? '—') }}</td>
                                <td class="px-4 py-3 text-xs text-cyan-200/60">{{ $plan->creator_name ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs text-cyan-200/45">{{ \Carbon\Carbon::parse($plan->created_at)->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3"><a href="{{ route('response.show', $plan->id) }}" class="text-xs text-cyan-400 hover:text-cyan-200 transition">Open →</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
