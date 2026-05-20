<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Playbook Version History — {{ $playbook->name }}</h2>
        <p class="text-xs text-amber-400/80 mt-1">SOAR workflows are simulation-first and approval-gated. No autonomous enforcement or destructive response is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Version History ({{ $versions->count() }} versions)</h3>
            <div class="space-y-2">
                @forelse($versions as $ver)
                <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                    <div class="flex items-center gap-2">
                        <span class="text-cyan-300 font-medium">v{{ $ver->version }}</span>
                        <span class="font-mono text-gray-500">{{ $ver->version_hash }}</span>
                        <span class="ml-auto text-gray-400">{{ $ver->created_at?->format('Y-m-d H:i') ?? '—' }}</span>
                    </div>
                    @if($ver->change_reason)
                    <div class="text-gray-400 mt-1">{{ $ver->change_reason }}</div>
                    @endif
                </div>
                @empty
                <p class="text-xs text-gray-500">No versions found.</p>
                @endforelse
            </div>
        </div>

        <a href="{{ route('soar.playbook-registry') }}" class="text-xs text-gray-400 hover:text-gray-300">← Back to Registry</a>

    </div>
</x-app-layout>
