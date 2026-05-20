<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Suppression Governance</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Detection lifecycle actions are governed, replay-validated, and do not execute autonomous response.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Suppressions are approval-gated. No automatic suppression. Suppressions do not delete original evidence or mutate historical alerts.
        </div>

        @if($expiring->isNotEmpty())
        <div class="glass-card p-4 border border-yellow-700/40 space-y-2">
            <h3 class="text-sm font-semibold text-yellow-300">Expiring Within 7 Days</h3>
            @foreach($expiring as $s)
            <div class="flex justify-between text-xs text-gray-300 border-b border-gray-700/40 pb-1">
                <span class="font-mono text-yellow-300">{{ $s->rule_id }}</span>
                <span>{{ $s->expires_at?->diffForHumans() }}</span>
            </div>
            @endforeach
        </div>
        @endif

        <div class="space-y-2">
            @forelse($suppressions as $supp)
            <div class="glass-card p-4 space-y-1 text-xs">
                <div class="flex justify-between">
                    <span class="font-mono text-orange-300">{{ $supp->rule_id }}</span>
                    <span class="text-xs px-2 py-0.5 rounded {{ $supp->is_active ? 'bg-green-900/30 text-green-300' : 'bg-gray-800 text-gray-500' }}">
                        {{ $supp->approval_state }}
                    </span>
                </div>
                <div class="flex gap-3 text-gray-400">
                    <span>scope: {{ $supp->scope }}</span>
                    @if($supp->scope_value)<span>value: <span class="text-gray-300">{{ $supp->scope_value }}</span></span>@endif
                    @if($supp->expires_at)<span>expires: <span class="text-yellow-300">{{ $supp->expires_at->toDateString() }}</span></span>@endif
                </div>
                <div class="text-gray-400 truncate">{{ $supp->reason }}</div>
            </div>
            @empty
            <p class="text-xs text-gray-500 p-4">No suppressions defined.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
