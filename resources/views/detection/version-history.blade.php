<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Rule Version History — {{ $rule['rule_id'] }}</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Detection lifecycle actions are governed, replay-validated, and do not execute autonomous response.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Rule version snapshots are immutable and append-only. Historical versions are never deleted or mutated.
        </div>

        <div class="glass-card p-4 space-y-2">
            <div class="flex gap-4 text-xs text-gray-400">
                <span>Domain: <span class="text-cyan-300">{{ $rule['domain'] }}</span></span>
                <span>Status: <span class="text-yellow-300">{{ $rule['status'] }}</span></span>
                <span>Shadow-only: <span class="text-green-300">{{ ($rule['shadow_only'] ?? true) ? 'true' : 'false' }}</span></span>
            </div>
        </div>

        <div class="space-y-2">
            @forelse($versions as $version)
            <div class="glass-card p-4 space-y-1 text-xs">
                <div class="flex justify-between items-center">
                    <span class="font-mono text-cyan-300">{{ $version->version_id }}</span>
                    <span class="text-gray-500">{{ $version->created_at?->diffForHumans() }}</span>
                </div>
                <div class="flex gap-4 text-gray-400">
                    <span>Stage: <span class="text-yellow-300">{{ $version->stage }}</span></span>
                    <span>Hash: <span class="font-mono text-gray-300">{{ substr($version->rule_hash, 0, 16) }}…</span></span>
                    <span>Author: <span class="text-gray-300">{{ $version->author?->name ?? 'system' }}</span></span>
                </div>
                @if($version->change_reason)
                <div class="text-gray-400">Reason: {{ $version->change_reason }}</div>
                @endif
                @if($version->previous_version_id)
                <div class="text-gray-600 text-xs">← {{ $version->previous_version_id }}</div>
                @endif
            </div>
            @empty
            <p class="text-xs text-gray-500 p-4">No version snapshots recorded yet.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
