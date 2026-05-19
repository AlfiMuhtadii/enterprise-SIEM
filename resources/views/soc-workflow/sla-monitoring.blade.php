<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">SLA Monitoring Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Analyst-driven collaborative workflows only. No autonomous SOC operations.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-6xl mx-auto space-y-6">
        <div class="grid grid-cols-4 gap-4">
            @foreach([
                ['Total Breaches', $breachSummary['total'] ?? 0, 'text-gray-300'],
                ['Unacknowledged', $breachSummary['unacked'] ?? 0, 'text-amber-400'],
                ['Critical Breaches', $breachSummary['critical'] ?? 0, 'text-red-400'],
                ['Overdue', $overdue->count(), 'text-orange-400'],
            ] as [$label, $val, $color])
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $val }}</div>
            </div>
            @endforeach
        </div>

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Recent SLA Breaches</h3>
            @forelse($recentBreaches as $b)
            <div class="flex gap-3 items-center py-1 border-b border-gray-800 text-xs">
                <span class="font-mono text-cyan-400 text-xs">{{ $b->investigation_id }}</span>
                <span class="px-1.5 py-0.5 rounded bg-gray-700 text-gray-400">{{ $b->breach_type }}</span>
                <span class="{{ $b->severity === 'critical' ? 'text-red-400' : 'text-amber-400' }}">{{ $b->severity }}</span>
                <span class="text-gray-400">{{ gmdate('H:i:s', $b->overdue_seconds) }} overdue</span>
                <span class="{{ $b->acknowledged ? 'text-green-400' : 'text-amber-400' }}">{{ $b->acknowledged ? 'acked' : 'pending' }}</span>
                <span class="ml-auto text-gray-500">{{ \Carbon\Carbon::parse($b->created_at)->format('M d H:i') }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No SLA breaches recorded.</p>
            @endforelse
        </div>

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Active SLA Policies</h3>
            <table class="w-full text-xs text-left text-gray-300">
                <thead class="text-gray-400 uppercase border-b border-gray-700">
                    <tr><th class="py-2 pr-3">Type</th><th class="py-2 pr-3">Severity</th><th class="py-2">Threshold</th></tr>
                </thead>
                <tbody>
                    @foreach($policies as $p)
                    <tr class="border-b border-gray-800">
                        <td class="py-1.5 pr-3">{{ $p->policy_type }}</td>
                        <td class="py-1.5 pr-3">{{ $p->severity }}</td>
                        <td class="py-1.5">{{ gmdate('H:i:s', $p->max_seconds) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
