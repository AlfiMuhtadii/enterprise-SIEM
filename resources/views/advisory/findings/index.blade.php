<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Advisory Findings</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Shadow rule outputs — advisory only. No incidents are auto-created. Active alert boundary is strictly separate.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            These findings come from shadow/advisory detection rules. They do <strong>not</strong> create incidents.
            Promotion to active requires a domain-specific 6h soak PASS — see promotion_blocker per finding.
        </div>

        {{-- Summary bar --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            <div class="glass-card p-3 text-center">
                <div class="text-xs text-gray-400 uppercase mb-1">Total</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $summary['total'] }}</div>
            </div>
            <div class="glass-card p-3 text-center">
                <div class="text-xs text-gray-400 uppercase mb-1">New</div>
                <div class="text-2xl font-bold {{ $summary['new'] > 0 ? 'text-amber-400' : 'text-green-400' }}">{{ $summary['new'] }}</div>
            </div>
            <div class="glass-card p-3 text-center">
                <div class="text-xs text-gray-400 uppercase mb-1">Reviewed</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $summary['reviewed'] }}</div>
            </div>
            <div class="glass-card p-3 text-center">
                <div class="text-xs text-gray-400 uppercase mb-1">Dismissed</div>
                <div class="text-2xl font-bold text-gray-400">{{ $summary['dismissed'] }}</div>
            </div>
            <div class="glass-card p-3 text-center">
                <div class="text-xs text-gray-400 uppercase mb-1">Nominated</div>
                <div class="text-2xl font-bold text-purple-400">{{ $summary['nominated'] }}</div>
            </div>
            <div class="glass-card p-3 text-center">
                <div class="text-xs text-gray-400 uppercase mb-1">Promo Candidates</div>
                <div class="text-2xl font-bold text-green-400">{{ $summary['promotion_candidates'] }}</div>
            </div>
        </div>

        {{-- Filters --}}
        <form method="GET" action="{{ route('advisory.findings.index') }}" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="text-xs text-gray-400 block mb-1">Status</label>
                <select name="status" class="bg-gray-900 border border-gray-700 text-gray-200 text-xs rounded px-2 py-1">
                    <option value="">All</option>
                    @foreach(['new','reviewed','dismissed','nominated'] as $s)
                        <option value="{{ $s }}" {{ ($filters['status'] ?? '') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-400 block mb-1">Domain</label>
                <select name="domain" class="bg-gray-900 border border-gray-700 text-gray-200 text-xs rounded px-2 py-1">
                    <option value="">All</option>
                    @foreach($domains as $d)
                        <option value="{{ $d }}" {{ ($filters['domain'] ?? '') === $d ? 'selected' : '' }}>{{ $d }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-400 block mb-1">Severity</label>
                <select name="severity" class="bg-gray-900 border border-gray-700 text-gray-200 text-xs rounded px-2 py-1">
                    <option value="">All</option>
                    @foreach(['critical','high','medium','low','info'] as $sv)
                        <option value="{{ $sv }}" {{ ($filters['severity'] ?? '') === $sv ? 'selected' : '' }}>{{ ucfirst($sv) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-400 block mb-1">Promo candidates only</label>
                <select name="promotion_candidate" class="bg-gray-900 border border-gray-700 text-gray-200 text-xs rounded px-2 py-1">
                    <option value="">Any</option>
                    <option value="1" {{ ($filters['promotion_candidate'] ?? '') === '1' ? 'selected' : '' }}>Yes</option>
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-400 block mb-1">Rule ID</label>
                <input type="text" name="rule_id" value="{{ $filters['rule_id'] ?? '' }}"
                    placeholder="ENDPOINT_..." class="bg-gray-900 border border-gray-700 text-gray-200 text-xs rounded px-2 py-1 w-48">
            </div>
            <button type="submit" class="px-3 py-1 text-xs bg-cyan-700 text-white rounded hover:bg-cyan-600">Filter</button>
            <a href="{{ route('advisory.findings.index') }}" class="px-3 py-1 text-xs bg-gray-700 text-gray-300 rounded hover:bg-gray-600">Reset</a>
        </form>

        {{-- Findings table --}}
        <div class="glass-card overflow-hidden">
            <table class="min-w-full text-xs">
                <thead>
                    <tr class="border-b border-gray-700 text-gray-400 uppercase">
                        <th class="px-3 py-2 text-left">Severity</th>
                        <th class="px-3 py-2 text-left">Rule ID</th>
                        <th class="px-3 py-2 text-left">Domain</th>
                        <th class="px-3 py-2 text-left">Actor</th>
                        <th class="px-3 py-2 text-left">Tenant</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Occurrences</th>
                        <th class="px-3 py-2 text-left">Last Seen</th>
                        <th class="px-3 py-2 text-left">Promo</th>
                        <th class="px-3 py-2 text-left"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($findings as $f)
                    <tr class="border-b border-gray-800 hover:bg-gray-800/40">
                        <td class="px-3 py-2 font-mono">
                            <span class="px-1.5 py-0.5 rounded text-xs font-bold
                                {{ $f->severity === 'critical' ? 'bg-red-900/60 text-red-300' :
                                   ($f->severity === 'high'    ? 'bg-orange-900/60 text-orange-300' :
                                   ($f->severity === 'medium'  ? 'bg-yellow-900/60 text-yellow-300' :
                                   'bg-gray-800 text-gray-400')) }}">
                                {{ strtoupper($f->severity) }}
                            </span>
                        </td>
                        <td class="px-3 py-2 font-mono text-cyan-300 max-w-xs truncate">{{ $f->rule_id }}</td>
                        <td class="px-3 py-2 text-gray-300">{{ $f->domain }}</td>
                        <td class="px-3 py-2 text-gray-300 font-mono max-w-xs truncate">{{ $f->actor_key ?? '—' }}</td>
                        <td class="px-3 py-2 text-gray-400">{{ $f->tenant_id ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <span class="px-1.5 py-0.5 rounded text-xs
                                {{ $f->status === 'new'      ? 'bg-amber-900/50 text-amber-300' :
                                   ($f->status === 'nominated' ? 'bg-purple-900/50 text-purple-300' :
                                   ($f->status === 'dismissed' ? 'bg-gray-800 text-gray-500' :
                                   'bg-cyan-900/30 text-cyan-400')) }}">
                                {{ $f->status }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-center font-bold {{ $f->occurrence_count > 10 ? 'text-red-400' : 'text-gray-300' }}">{{ $f->occurrence_count }}</td>
                        <td class="px-3 py-2 text-gray-400">{{ $f->last_seen_at->diffForHumans() }}</td>
                        <td class="px-3 py-2 text-center">
                            @if($f->promotion_candidate)
                                <span class="text-green-400" title="{{ $f->promotion_blocker }}">★</span>
                            @else
                                <span class="text-gray-600">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            <a href="{{ route('advisory.findings.show', $f->finding_id) }}"
                               class="text-cyan-400 hover:text-cyan-300">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-3 py-6 text-center text-gray-500">
                            No advisory findings.
                            @if(env('XDR_SHADOW_CONSUMER_ENABLED') !== 'true')
                                <span class="text-amber-400 ml-2">Shadow consumer not enabled — set XDR_SHADOW_CONSUMER_ENABLED=true to start receiving findings.</span>
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="text-xs text-gray-500">{{ $findings->links() }}</div>
    </div>
</x-app-layout>
