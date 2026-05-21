<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Spool Health Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1">Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.
        </div>
        <div class="flex justify-end">
            <a href="{{ route('endpoint-fleet.dashboard') }}" class="text-xs text-cyan-400 hover:underline">← Fleet Dashboard</a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach([
                ['Total Agents', $totalAgents, 'text-gray-300'],
                ['Spool Warnings', $warnings, 'text-yellow-400'],
                ['Monitored Spools', $spoolSummary->count(), 'text-cyan-300'],
            ] as [$label, $val, $color])
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $val }}</div>
            </div>
            @endforeach
        </div>

        <div class="glass-card p-0 overflow-hidden">
            <div class="flex justify-between items-center px-4 py-3 border-b border-gray-700">
                <span class="text-sm font-semibold text-cyan-200">Spool Health by Agent (Highest Dropped First)</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-800/60 text-gray-400 uppercase tracking-wider">
                        <tr>
                            <th class="px-3 py-2 text-left">Hostname</th>
                            <th class="px-3 py-2 text-right">Queued</th>
                            <th class="px-3 py-2 text-right">Dropped</th>
                            <th class="px-3 py-2 text-right">Retries</th>
                            <th class="px-3 py-2 text-right">Spool (bytes)</th>
                            <th class="px-3 py-2 text-center">Capped</th>
                            <th class="px-3 py-2 text-center">Disk Press.</th>
                            <th class="px-3 py-2 text-left">Recorded</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                    @forelse($spoolSummary as $row)
                        <tr class="hover:bg-gray-800/30">
                            <td class="px-3 py-2 text-cyan-400">{{ $row->hostname }}</td>
                            <td class="px-3 py-2 text-right text-gray-300">{{ $row->queued_events }}</td>
                            <td class="px-3 py-2 text-right {{ $row->dropped_events > 0 ? 'text-red-400 font-bold' : 'text-gray-500' }}">{{ $row->dropped_events }}</td>
                            <td class="px-3 py-2 text-right {{ $row->retry_count > 0 ? 'text-yellow-400' : 'text-gray-500' }}">{{ $row->retry_count }}</td>
                            <td class="px-3 py-2 text-right text-gray-300">{{ number_format($row->spool_disk_bytes) }}</td>
                            <td class="px-3 py-2 text-center">
                                <span class="{{ $row->spool_capped ? 'text-red-400' : 'text-gray-600' }}">{{ $row->spool_capped ? 'yes' : 'no' }}</span>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <span class="{{ $row->disk_pressure ? 'text-orange-400' : 'text-gray-600' }}">{{ $row->disk_pressure ? 'yes' : 'no' }}</span>
                            </td>
                            <td class="px-3 py-2 text-gray-500">{{ \Carbon\Carbon::parse($row->recorded_at)->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center text-gray-500">No spool health data available.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-xs text-gray-600 italic">Spool cap = 10 MiB. High dropped_events indicates telemetry loss due to connectivity or capacity issues. Investigate manually — no automated action.</p>
    </div>
</x-app-layout>
