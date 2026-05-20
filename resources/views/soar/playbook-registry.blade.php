<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">SOAR Playbook Registry</h2>
        <p class="text-xs text-amber-400/80 mt-1">SOAR workflows are simulation-first and approval-gated. No autonomous enforcement or destructive response is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> SOAR workflows are simulation-first and approval-gated. No autonomous enforcement or destructive response is executed.
        </div>

        <div class="grid grid-cols-3 gap-4">
            @foreach([
                ['Total Playbooks', $stats['total_playbooks'], 'text-cyan-300'],
                ['Active', $stats['active_playbooks'], 'text-green-300'],
                ['Pending Approvals', $stats['pending_approvals'], 'text-yellow-300'],
            ] as [$label, $value, $color])
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $value }}</div>
            </div>
            @endforeach
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Governed Playbooks ({{ $playbooks->count() }})</h3>
            <div class="space-y-2">
                @forelse($playbooks as $pb)
                <div class="flex items-center justify-between py-2 border-b border-gray-800 last:border-0 text-xs">
                    <div>
                        <span class="text-cyan-300 font-medium">{{ $pb->name }}</span>
                        <span class="ml-2 font-mono text-gray-500">{{ $pb->playbook_id }}</span>
                        <span class="ml-2 px-1.5 py-0.5 rounded
                            @if($pb->status === 'active') bg-green-900/40 text-green-300
                            @elseif($pb->status === 'deprecated') bg-gray-700 text-gray-400
                            @else bg-blue-900/40 text-blue-300 @endif">{{ $pb->status }}</span>
                        <span class="ml-2 text-gray-400">v{{ $pb->version }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-gray-400">
                        <span>{{ $pb->execution_scope }}</span>
                        @if($pb->simulation_required)
                        <span class="text-blue-400">SIM-REQUIRED</span>
                        @endif
                        <a href="{{ route('soar.playbook-version-history', ['playbookId' => $pb->playbook_id]) }}" class="text-blue-400 hover:text-blue-300">Versions</a>
                    </div>
                </div>
                @empty
                <p class="text-xs text-gray-500">No playbooks defined yet.</p>
                @endforelse
            </div>
        </div>

    </div>
</x-app-layout>
