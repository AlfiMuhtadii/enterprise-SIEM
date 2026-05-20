<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Promotion Request Workflow</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Detection lifecycle actions are governed, replay-validated, and do not execute autonomous response.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> No autonomous promotion is performed. Every promotion requires operator approval and gate validation. Approving a request does not automatically promote the rule — operator must confirm via the Detection Governance workflow.
        </div>

        @if($pending->isNotEmpty())
        <div class="glass-card p-4 border border-yellow-700/40 space-y-2">
            <h3 class="text-sm font-semibold text-yellow-300">Pending Promotion Requests ({{ $pending->count() }})</h3>
            @foreach($pending as $req)
            <div class="text-xs border-b border-gray-700/40 pb-2 space-y-1">
                <div class="flex justify-between">
                    <span class="font-mono text-yellow-300">{{ $req->rule_id }}</span>
                    <span class="text-gray-500">{{ $req->created_at?->diffForHumans() }}</span>
                </div>
                <div class="text-gray-400">{{ $req->from_stage }} → <span class="text-cyan-300">{{ $req->to_stage }}</span></div>
                @if($req->rationale)<div class="text-gray-500 truncate">{{ $req->rationale }}</div>@endif
                @if(isset($req->gate_snapshot['passed']))<div class="text-xs {{ $req->gate_snapshot['passed'] ? 'text-green-400' : 'text-red-400' }}">Gates: {{ $req->gate_snapshot['passed'] ? 'PASS' : 'FAIL' }}</div>@endif
            </div>
            @endforeach
        </div>
        @endif

        <div class="space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">All Promotion Requests</h3>
            @forelse($all as $req)
            <div class="glass-card p-4 space-y-1 text-xs">
                <div class="flex justify-between items-center">
                    <span class="font-mono text-gray-300">{{ $req->rule_id }}</span>
                    <span class="px-2 py-0.5 rounded text-xs
                        {{ $req->status === 'approved' ? 'bg-green-900/30 text-green-300' :
                           ($req->status === 'rejected' ? 'bg-red-900/30 text-red-300' :
                           ($req->status === 'pending' ? 'bg-yellow-900/30 text-yellow-300' : 'bg-gray-800 text-gray-400')) }}">
                        {{ $req->status }}
                    </span>
                </div>
                <div class="text-gray-400">{{ $req->from_stage }} → {{ $req->to_stage }} &nbsp;·&nbsp; {{ $req->created_at?->diffForHumans() }}</div>
                @if($req->reviewed_by)<div class="text-gray-500">Reviewed by {{ $req->reviewed_by }}: {{ $req->review_note }}</div>@endif
            </div>
            @empty
            <p class="text-xs text-gray-500">No promotion requests.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
