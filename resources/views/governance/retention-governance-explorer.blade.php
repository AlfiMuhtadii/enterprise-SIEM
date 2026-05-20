<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Retention Governance Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Governance and compliance workflows are audit-visible and replay-safe. No autonomous remediation or destructive evidence mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Notice:</strong> Retention policy changes require review visibility. No automatic destructive purge is executed.
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Total Versions</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $versions->count() }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Pending Review</div>
                <div class="text-2xl font-bold text-yellow-300">{{ $pending->count() }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">Approved</div>
                <div class="text-2xl font-bold text-green-300">{{ $versions->where('review_status', 'approved')->count() }}</div>
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Policy Versions</h3>
            @forelse($versions as $v)
            <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                <div class="flex items-center gap-2">
                    <span class="font-mono text-gray-400">{{ $v->policy_id }}</span>
                    <span class="text-gray-300">v{{ $v->version }}</span>
                    <span class="px-1.5 py-0.5 rounded
                        @if($v->review_status === 'approved') bg-green-900/40 text-green-300
                        @elseif($v->review_status === 'pending') bg-yellow-900/40 text-yellow-300
                        @elseif($v->review_status === 'rejected') bg-red-900/40 text-red-300
                        @else bg-gray-700 text-gray-400 @endif">{{ $v->review_status }}</span>
                    <span class="font-mono text-gray-600 text-xs">{{ substr($v->version_hash, 0, 12) }}…</span>
                </div>
                @if($v->change_reason)<div class="text-gray-500 mt-0.5 pl-1">{{ $v->change_reason }}</div>@endif
            </div>
            @empty
            <p class="text-xs text-gray-500">No retention policy versions recorded.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
