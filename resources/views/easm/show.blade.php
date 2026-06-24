<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">EASM Asset: {{ $asset->name }}</h2><p class="text-xs text-amber-400/80 mt-1">Passive posture findings are advisory-only. No active scanning, no exploitation, no containment.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Advisory-only. Findings are for posture awareness only and do not create incidents.
        </div>

        {{-- Asset Summary --}}
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Asset Details</h3>
            <dl class="grid grid-cols-2 gap-2 text-xs text-slate-300">
                <dt class="text-slate-500">URL</dt><dd class="font-mono">{{ $asset->url }}</dd>
                <dt class="text-slate-500">Hostname</dt><dd class="font-mono">{{ $asset->hostname }}</dd>
                <dt class="text-slate-500">Scheme</dt><dd>{{ $asset->scheme }}</dd>
                <dt class="text-slate-500">Status</dt><dd class="{{ $asset->status === 'active' ? 'text-green-400' : 'text-yellow-400' }}">{{ $asset->status }}</dd>
                <dt class="text-slate-500">Scan Policy</dt><dd>{{ $asset->scan_policy }}</dd>
                <dt class="text-slate-500">Last Scanned</dt><dd>{{ $asset->last_scanned_at?->diffForHumans() ?? 'never' }}</dd>
            </dl>
        </div>

        {{-- Latest Scan Run --}}
        @if($latestRun)
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Latest Scan Run</h3>
            <dl class="grid grid-cols-2 gap-2 text-xs text-slate-300">
                <dt class="text-slate-500">Run ID</dt><dd class="font-mono">{{ $latestRun->run_id }}</dd>
                <dt class="text-slate-500">Status</dt><dd>{{ $latestRun->status }}</dd>
                <dt class="text-slate-500">Started</dt><dd>{{ $latestRun->started_at }}</dd>
                <dt class="text-slate-500">Finished</dt><dd>{{ $latestRun->finished_at ?? '—' }}</dd>
                <dt class="text-slate-500">Finding Count</dt><dd>{{ $latestRun->finding_count }}</dd>
                <dt class="text-slate-500">Dry Run</dt><dd>{{ $latestRun->is_dry_run ? 'yes' : 'no' }}</dd>
            </dl>
        </div>
        @endif

        {{-- Findings --}}
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Findings ({{ $findings->count() }})</h3>
            @if($findings->count() === 0)
                <p class="text-xs text-slate-500">No findings yet. Run a passive scan to populate findings.</p>
            @else
            @foreach(['high','medium','low','info'] as $sev)
            @php $sevFindings = $findings->where('severity', $sev) @endphp
            @if($sevFindings->count() > 0)
            <div class="mb-4">
                <h4 class="text-xs font-semibold mb-2 {{ $sev === 'high' ? 'text-red-400' : ($sev === 'medium' ? 'text-orange-400' : ($sev === 'low' ? 'text-yellow-400' : 'text-slate-400')) }}">
                    {{ strtoupper($sev) }} ({{ $sevFindings->count() }})
                </h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-slate-300">
                        <thead><tr class="text-slate-500 border-b border-slate-700">
                            <th class="text-left py-1">Key</th>
                            <th class="text-left py-1">Category</th>
                            <th class="text-left py-1">Title</th>
                            <th class="text-left py-1">Status</th>
                            <th class="text-left py-1">Last Seen</th>
                        </tr></thead>
                        <tbody>
                        @foreach($sevFindings as $f)
                        <tr class="border-b border-slate-800">
                            <td class="py-1 font-mono">{{ $f->finding_key }}</td>
                            <td class="py-1">{{ $f->category }}</td>
                            <td class="py-1">{{ $f->title }}</td>
                            <td class="py-1">{{ $f->status }}</td>
                            <td class="py-1">{{ $f->last_seen_at->diffForHumans() }}</td>
                        </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif
            @endforeach
            @endif
        </div>

        <div class="mt-2">
            <a href="{{ route('soc.easm.index') }}" class="text-xs text-cyan-400 hover:underline">Back to Assets</a>
        </div>
    </div>
</x-app-layout>
