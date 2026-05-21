<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">SOC Operations Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Analyst-driven collaborative workflows only. No autonomous SOC operations.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            All escalations, assignments, and workflow transitions require explicit operator action. No autonomous SOC operations.
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Open Escalations</div>
                <div class="text-2xl font-bold {{ $escalationQueue->count() > 0 ? 'text-red-400' : 'text-green-400' }}">{{ $escalationQueue->count() }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">SLA Breaches</div>
                <div class="text-2xl font-bold {{ ($breachSummary['unacked'] ?? 0) > 0 ? 'text-amber-400' : 'text-green-400' }}">{{ $breachSummary['unacked'] ?? 0 }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Timed-Out Escalations</div>
                <div class="text-2xl font-bold {{ $timedOut->count() > 0 ? 'text-red-400' : 'text-green-400' }}">{{ $timedOut->count() }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Analyst Queues</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $workload->count() }}</div>
            </div>
        </div>

        @if($timedOut->count() > 0)
        <div>
            <h3 class="text-sm font-semibold text-red-400 mb-2">Timed-Out Escalations (Advisory)</h3>
            @foreach($timedOut as $t)
            <div class="text-xs flex gap-3 py-1 border-b border-gray-800">
                <span class="font-mono text-cyan-400">{{ $t['escalation_id'] }}</span>
                <span>{{ $t['severity'] }}</span>
                <span class="text-red-400">{{ $t['hours_overdue'] }}h overdue</span>
                <span class="text-gray-500 ml-auto">advisory only — no auto-action</span>
            </div>
            @endforeach
        </div>
        @endif

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Analyst Workload</h3>
            @forelse($workload as $w)
            <div class="text-xs flex gap-3 py-1 border-b border-gray-800 items-center">
                <span class="text-gray-300 w-40">Analyst #{{ $w->assigned_to }}</span>
                <span class="font-bold {{ $w->open_count > 10 ? 'text-red-400' : ($w->open_count > 5 ? 'text-amber-400' : 'text-green-400') }}">{{ $w->open_count }} open</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No active investigation assignments.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
