<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Governance Access Review Console</h2>
        <p class="text-xs text-amber-400/80 mt-1">Governance and compliance workflows are audit-visible and replay-safe. No autonomous remediation or destructive evidence mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        @if($stale->count() > 0)
        <div class="rounded border border-yellow-500/40 bg-yellow-900/10 px-4 py-3 text-sm text-yellow-300">
            <strong>⚠ Stale Privilege Accounts:</strong> {{ $stale->count() }} review(s) for accounts not reviewed in {{ \App\Models\GovernanceAccessReview::STALE_THRESHOLD_DAYS }}+ days.
        </div>
        @endif

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Access Reviews ({{ $reviews->count() }})</h3>
            @forelse($reviews as $r)
            <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                <div class="flex items-center gap-2">
                    <span class="text-cyan-300">{{ $r->subject_user }}</span>
                    <span class="text-gray-400">{{ $r->privileged_role }}</span>
                    <span class="px-1.5 py-0.5 rounded
                        @if($r->review_status === 'approved') bg-green-900/40 text-green-300
                        @elseif($r->review_status === 'revoked') bg-red-900/40 text-red-300
                        @elseif($r->review_status === 'in_review') bg-blue-900/40 text-blue-300
                        @elseif(in_array($r->review_status, ['open','expired'])) bg-yellow-900/40 text-yellow-300
                        @else bg-gray-700 text-gray-400 @endif">{{ $r->review_status }}</span>
                    @if($r->is_stale)<span class="text-red-400">[STALE: {{ $r->days_since_last_review }}d]</span>@endif
                </div>
            </div>
            @empty
            <p class="text-xs text-gray-500">No access reviews.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
