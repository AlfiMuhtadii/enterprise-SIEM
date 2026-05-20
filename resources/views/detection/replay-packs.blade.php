<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Replay Pack Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Detection lifecycle actions are governed, replay-validated, and do not execute autonomous response.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Replay packs are deterministic test fixtures. Running a replay does not trigger autonomous response.
        </div>

        <div class="space-y-2">
            @forelse($packs as $pack)
            <div class="glass-card p-4 space-y-1 text-xs">
                <div class="flex justify-between">
                    <span class="font-semibold text-cyan-300">{{ $pack->name }}</span>
                    <span class="text-gray-500 font-mono">{{ $pack->pack_id }}</span>
                </div>
                <div class="flex gap-4 text-gray-400">
                    <span>Rule: <span class="font-mono text-gray-300">{{ $pack->rule_id }}</span></span>
                    <span>Expected matches: <span class="text-green-300">{{ $pack->expected_match_count }}</span></span>
                    <span>Expected non-matches: <span class="text-red-300">{{ $pack->expected_non_match_count }}</span></span>
                    <span>Version: {{ $pack->pack_version }}</span>
                </div>
                @if($pack->description)
                <div class="text-gray-500">{{ $pack->description }}</div>
                @endif
            </div>
            @empty
            <p class="text-xs text-gray-500 p-4">No replay packs defined yet.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
