<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Advisory Finding</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Shadow rule output — advisory only. No incident created.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-6">

        @if(session('success'))
            <div class="rounded border border-green-500/30 bg-green-900/20 px-4 py-2 text-sm text-green-300">
                {{ session('success') }}
            </div>
        @endif

        {{-- Finding header --}}
        <div class="glass-card p-5 space-y-3">
            <div class="flex flex-wrap gap-4 items-start justify-between">
                <div>
                    <div class="text-xs text-gray-400 mb-1">Finding ID</div>
                    <div class="font-mono text-cyan-300 text-sm">{{ $finding->finding_id }}</div>
                </div>
                <span class="px-2 py-1 rounded text-xs font-bold
                    {{ $finding->severity === 'critical' ? 'bg-red-900/60 text-red-300' :
                       ($finding->severity === 'high'    ? 'bg-orange-900/60 text-orange-300' :
                       ($finding->severity === 'medium'  ? 'bg-yellow-900/60 text-yellow-300' :
                       'bg-gray-800 text-gray-400')) }}">
                    {{ strtoupper($finding->severity) }}
                </span>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-xs">
                <div><span class="text-gray-400">Rule ID</span><br><span class="font-mono text-cyan-200">{{ $finding->rule_id }}</span></div>
                <div><span class="text-gray-400">Domain</span><br><span class="text-gray-200">{{ $finding->domain }}</span></div>
                <div><span class="text-gray-400">Alert Type</span><br><span class="font-mono text-gray-200">{{ $finding->alert_type }}</span></div>
                <div><span class="text-gray-400">Actor / Host</span><br><span class="font-mono text-gray-200">{{ $finding->actor_key ?? '—' }}</span></div>
                <div><span class="text-gray-400">Tenant</span><br><span class="text-gray-200">{{ $finding->tenant_id ?? '—' }}</span></div>
                <div><span class="text-gray-400">Confidence</span><br><span class="text-gray-200">{{ $finding->confidence !== null ? number_format($finding->confidence * 100, 0) . '%' : '—' }}</span></div>
                <div><span class="text-gray-400">Source Topic</span><br><span class="font-mono text-gray-400 text-xs">{{ $finding->source_topic }}</span></div>
                <div><span class="text-gray-400">Occurrences</span><br><span class="font-bold {{ $finding->occurrence_count > 10 ? 'text-red-400' : 'text-gray-200' }}">{{ $finding->occurrence_count }}</span></div>
                <div><span class="text-gray-400">Status</span><br>
                    <span class="px-1.5 py-0.5 rounded text-xs
                        {{ $finding->status === 'new'      ? 'bg-amber-900/50 text-amber-300' :
                           ($finding->status === 'nominated' ? 'bg-purple-900/50 text-purple-300' :
                           ($finding->status === 'dismissed' ? 'bg-gray-800 text-gray-500' :
                           'bg-cyan-900/30 text-cyan-400')) }}">
                        {{ $finding->status }}
                    </span>
                </div>
                <div><span class="text-gray-400">First Seen</span><br><span class="text-gray-300">{{ $finding->first_seen_at->format('Y-m-d H:i') }}</span></div>
                <div><span class="text-gray-400">Last Seen</span><br><span class="text-gray-300">{{ $finding->last_seen_at->format('Y-m-d H:i') }}</span></div>
            </div>

            {{-- Promotion candidacy --}}
            <div class="border-t border-gray-700 pt-3 text-xs">
                <span class="text-gray-400">Promotion Candidate: </span>
                @if($finding->promotion_candidate)
                    <span class="text-green-400 font-bold">YES</span>
                @else
                    <span class="text-gray-500">No</span>
                @endif
                @if($finding->promotion_blocker)
                    <span class="ml-3 text-amber-400/80">⚠ {{ $finding->promotion_blocker }}</span>
                @endif
            </div>
        </div>

        {{-- Evidence --}}
        @if($finding->evidence)
        <div class="glass-card p-4">
            <div class="text-xs text-gray-400 uppercase mb-2">Source Evidence</div>
            <pre class="text-xs text-gray-300 overflow-x-auto font-mono bg-gray-900/60 rounded p-3 max-h-64 overflow-y-auto">{{ json_encode($finding->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
        @endif

        @if($finding->source_event_ids && count($finding->source_event_ids) > 0)
        <div class="glass-card p-4">
            <div class="text-xs text-gray-400 uppercase mb-2">Source Event IDs</div>
            <div class="flex flex-wrap gap-2">
                @foreach($finding->source_event_ids as $eid)
                    <span class="font-mono text-xs bg-gray-800 text-gray-300 px-2 py-0.5 rounded">{{ $eid }}</span>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Analyst action --}}
        @can('soc:advisory.review')
        @if(in_array($finding->status, ['new', 'reviewed']))
        <div class="glass-card p-4">
            <div class="text-xs text-gray-400 uppercase mb-3">Analyst Action</div>
            <form method="POST" action="{{ route('advisory.findings.review', $finding->finding_id) }}" class="space-y-3">
                @csrf
                <div class="flex gap-3">
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input type="radio" name="action" value="review" class="accent-cyan-500" required>
                        <span class="text-xs text-cyan-300">Mark Reviewed</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input type="radio" name="action" value="dismiss" class="accent-gray-500">
                        <span class="text-xs text-gray-400">Dismiss</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input type="radio" name="action" value="nominate" class="accent-purple-500">
                        <span class="text-xs text-purple-300">Nominate for Promotion</span>
                    </label>
                </div>
                <textarea name="note" placeholder="Optional analyst note (max 1000 chars)"
                    class="w-full bg-gray-900 border border-gray-700 text-gray-200 text-xs rounded px-3 py-2 h-20 resize-none"
                    maxlength="1000"></textarea>
                <div class="flex gap-3 items-center">
                    <button type="submit" class="px-4 py-1.5 text-xs bg-cyan-700 text-white rounded hover:bg-cyan-600">Submit</button>
                    <span class="text-xs text-amber-400/60">Nominating does not promote the rule — promotion requires domain soak PASS.</span>
                </div>
            </form>
        </div>
        @endif
        @endcan

        {{-- Review note --}}
        @if($finding->review_note)
        <div class="glass-card p-4">
            <div class="text-xs text-gray-400 uppercase mb-2">Review Note</div>
            <p class="text-sm text-gray-300">{{ $finding->review_note }}</p>
            <p class="text-xs text-gray-500 mt-1">Reviewed {{ $finding->reviewed_at?->format('Y-m-d H:i') }} by analyst #{{ $finding->reviewed_by }}</p>
        </div>
        @endif

        {{-- Audit trail --}}
        <div class="glass-card p-4">
            <div class="text-xs text-gray-400 uppercase mb-3">Audit Trail</div>
            @forelse($events as $ev)
            <div class="text-xs flex gap-3 py-1.5 border-b border-gray-800 last:border-0">
                <span class="text-gray-500 w-36 shrink-0">{{ $ev->created_at->format('Y-m-d H:i:s') }}</span>
                <span class="font-mono text-cyan-400">{{ $ev->event_type }}</span>
                @if($ev->analyst_id)
                    <span class="text-gray-400">analyst #{{ $ev->analyst_id }}</span>
                @endif
                @if(!empty($ev->metadata))
                    <span class="text-gray-600 truncate">{{ json_encode($ev->metadata) }}</span>
                @endif
            </div>
            @empty
            <p class="text-xs text-gray-600">No events recorded.</p>
            @endforelse
        </div>

        <div class="text-xs text-gray-600">
            <a href="{{ route('advisory.findings.index') }}" class="text-cyan-600 hover:text-cyan-400">← Back to Advisory Findings</a>
        </div>
    </div>
</x-app-layout>
