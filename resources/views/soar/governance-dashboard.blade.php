<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">SOAR Response Governance Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">SOAR workflows are simulation-first and approval-gated. No autonomous enforcement or destructive response is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> SOAR workflows are simulation-first and approval-gated. No autonomous enforcement or destructive response is executed.
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('soar.playbook-registry') }}" class="px-3 py-1.5 text-xs rounded bg-cyan-900/30 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/50">Playbook Registry</a>
            <a href="{{ route('soar.simulation-runner') }}" class="px-3 py-1.5 text-xs rounded bg-blue-900/30 text-blue-300 border border-blue-700/40 hover:bg-blue-900/50">Simulation Runner</a>
            <a href="{{ route('soar.approval-console') }}" class="px-3 py-1.5 text-xs rounded bg-yellow-900/30 text-yellow-300 border border-yellow-700/40 hover:bg-yellow-900/50">Approval Console</a>
            <a href="{{ route('soar.rollback-viewer') }}" class="px-3 py-1.5 text-xs rounded bg-orange-900/30 text-orange-300 border border-orange-700/40 hover:bg-orange-900/50">Rollback Plans</a>
            <a href="{{ route('soar.audit-timeline') }}" class="px-3 py-1.5 text-xs rounded bg-purple-900/30 text-purple-300 border border-purple-700/40 hover:bg-purple-900/50">Audit Timeline</a>
            <a href="{{ route('soar.simulation-artifacts') }}" class="px-3 py-1.5 text-xs rounded bg-green-900/30 text-green-300 border border-green-700/40 hover:bg-green-900/50">Simulation Artifacts</a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach([
                ['Active Playbooks', $stats['active_playbooks'], 'text-cyan-300'],
                ['Total Plans', $stats['total_plans'], 'text-blue-300'],
                ['Pending Approvals', $stats['pending_approvals'], 'text-yellow-400'],
                ['Simulations Run', $stats['total_simulations'], 'text-green-300'],
                ['Approval Requests', $stats['total_approval_requests'], 'text-purple-300'],
                ['Audit Events', $stats['total_audit_events'], 'text-gray-300'],
                ['Execution Results', $stats['total_execution_results'], 'text-teal-300'],
                ['Plans Awaiting Approval', $stats['plans_pending_approval'], 'text-orange-300'],
            ] as [$label, $value, $color])
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $value }}</div>
            </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-xs">
            <div class="rounded border border-green-700/30 bg-green-900/10 px-4 py-3">
                <div class="text-green-300 font-medium">Simulation Required</div>
                <div class="text-green-200 mt-1">{{ $stats['simulation_required'] ? 'YES — enforced on all plans' : 'NO' }}</div>
            </div>
            <div class="rounded border border-green-700/30 bg-green-900/10 px-4 py-3">
                <div class="text-green-300 font-medium">Autonomous Execution</div>
                <div class="text-green-200 mt-1">{{ $stats['autonomous_execution'] ? 'ENABLED (WARNING)' : 'DISABLED — approval required' }}</div>
            </div>
            <div class="rounded border border-green-700/30 bg-green-900/10 px-4 py-3">
                <div class="text-green-300 font-medium">Advisory Mode</div>
                <div class="text-green-200 mt-1">{{ $stats['advisory_only'] ? 'YES — all outputs are advisory' : 'NO (WARNING)' }}</div>
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Recent Execution Results</h3>
            @forelse($results as $r)
            <div class="flex items-center gap-2 py-1.5 border-b border-gray-800 last:border-0 text-xs">
                <span class="@if($r->success) text-green-300 @else text-red-300 @endif">{{ $r->success ? '✓' : '✗' }}</span>
                <span class="text-gray-300">{{ $r->result_type }}</span>
                <span class="text-gray-500">{{ Str::limit($r->result_summary, 60) }}</span>
                @if($r->is_simulation)
                <span class="ml-auto px-1.5 py-0.5 rounded bg-blue-900/40 text-blue-300">SIM</span>
                @endif
            </div>
            @empty
            <p class="text-xs text-gray-500">No execution results yet.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
