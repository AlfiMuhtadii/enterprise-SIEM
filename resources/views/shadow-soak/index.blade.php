<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Shadow Domain Soak Harness</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory-only evidence accumulation. No rules are promoted. Active alert boundary is strictly separate.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Evidence here is <strong>advisory only</strong>. Promotion to active requires a domain-specific 6h soak PASS
            and explicit operator decision. <code>ACTIVE_ALLOWLIST</code> is never modified here.
        </div>

        {{-- Summary --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
            <div class="glass-card p-3 text-center">
                <div class="text-xs text-gray-400 uppercase mb-1">Total Runs</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $summary['total'] }}</div>
            </div>
            <div class="glass-card p-3 text-center">
                <div class="text-xs text-gray-400 uppercase mb-1">Completed</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $summary['completed'] }}</div>
            </div>
            <div class="glass-card p-3 text-center">
                <div class="text-xs text-gray-400 uppercase mb-1">Evidence PASS</div>
                <div class="text-2xl font-bold text-green-400">{{ $summary['evidence_pass'] }}</div>
            </div>
            <div class="glass-card p-3 text-center">
                <div class="text-xs text-gray-400 uppercase mb-1">Failed</div>
                <div class="text-2xl font-bold {{ $summary['failed'] > 0 ? 'text-red-400' : 'text-gray-400' }}">{{ $summary['failed'] }}</div>
            </div>
            <div class="glass-card p-3 text-center">
                <div class="text-xs text-gray-400 uppercase mb-1">Running</div>
                <div class="text-2xl font-bold text-yellow-400">{{ $summary['running'] }}</div>
            </div>
        </div>

        {{-- New run form --}}
        @can('soc:shadow.soak.run')
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Start Soak Run</h3>
            <form method="POST" action="{{ route('shadow-soak.store') }}" class="flex flex-wrap gap-3 items-end">
                @csrf
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Domain</label>
                    <select name="domain" class="bg-gray-900 border border-gray-700 text-gray-200 text-xs rounded px-2 py-1" required>
                        @foreach(['endpoint','network','ueba'] as $d)
                            <option value="{{ $d }}">{{ ucfirst($d) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Window (hours)</label>
                    <input type="number" name="hours" value="24" min="1" max="168"
                        class="bg-gray-900 border border-gray-700 text-gray-200 text-xs rounded px-2 py-1 w-20">
                </div>
                <div class="flex items-center gap-2 mt-4">
                    <input type="checkbox" name="dry_run" value="1" id="dry_run" class="accent-amber-400">
                    <label for="dry_run" class="text-xs text-gray-400">Dry run</label>
                </div>
                <button type="submit" class="bg-cyan-700 hover:bg-cyan-600 text-white text-xs px-3 py-1.5 rounded">
                    Run Soak
                </button>
            </form>
        </div>
        @endcan

        {{-- Filters --}}
        <form method="GET" action="{{ route('shadow-soak.index') }}" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="text-xs text-gray-400 block mb-1">Domain</label>
                <select name="domain" class="bg-gray-900 border border-gray-700 text-gray-200 text-xs rounded px-2 py-1">
                    <option value="">All</option>
                    @foreach(['endpoint','network','ueba'] as $d)
                        <option value="{{ $d }}" {{ ($filters['domain'] ?? '') === $d ? 'selected' : '' }}>{{ ucfirst($d) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-400 block mb-1">Status</label>
                <select name="status" class="bg-gray-900 border border-gray-700 text-gray-200 text-xs rounded px-2 py-1">
                    <option value="">All</option>
                    @foreach(['running','completed','failed','dry_run'] as $s)
                        <option value="{{ $s }}" {{ ($filters['status'] ?? '') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bg-gray-700 hover:bg-gray-600 text-gray-200 text-xs px-3 py-1.5 rounded">Filter</button>
        </form>

        {{-- Runs table --}}
        <div class="glass-card overflow-x-auto">
            <table class="w-full text-xs text-gray-300">
                <thead class="text-gray-400 border-b border-gray-700">
                    <tr>
                        <th class="px-3 py-2 text-left">Run ID</th>
                        <th class="px-3 py-2 text-left">Domain</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Evidence</th>
                        <th class="px-3 py-2 text-left">Hours</th>
                        <th class="px-3 py-2 text-left">Started</th>
                        <th class="px-3 py-2 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($runs as $run)
                    <tr class="hover:bg-gray-800/40">
                        <td class="px-3 py-2 font-mono text-cyan-400/80 text-xs">{{ $run->run_id }}</td>
                        <td class="px-3 py-2">{{ $run->domain }}</td>
                        <td class="px-3 py-2">
                            <span class="px-1.5 py-0.5 rounded text-xs font-medium
                                {{ $run->status === 'completed' ? 'bg-green-900/40 text-green-300' : '' }}
                                {{ $run->status === 'failed'    ? 'bg-red-900/40 text-red-300' : '' }}
                                {{ $run->status === 'running'   ? 'bg-yellow-900/40 text-yellow-300' : '' }}
                                {{ $run->status === 'dry_run'   ? 'bg-gray-700 text-gray-300' : '' }}">
                                {{ $run->status }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            @if($run->status === 'completed')
                                <span class="{{ $run->evidence_pass ? 'text-green-400' : 'text-red-400' }}">
                                    {{ $run->evidence_pass ? 'PASS' : 'FAIL' }}
                                </span>
                            @else
                                <span class="text-gray-500">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2">{{ $run->window_hours }}h</td>
                        <td class="px-3 py-2">{{ $run->started_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2">
                            <a href="{{ route('shadow-soak.show', $run->run_id) }}" class="text-cyan-400 hover:underline">View</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-3 py-4 text-center text-gray-500">No soak runs yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-3 py-2">{{ $runs->links() }}</div>
        </div>

    </div>
</x-app-layout>
