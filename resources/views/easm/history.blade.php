<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            EASM — Posture History: {{ $asset->name }}
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        {{-- Advisory banner --}}
        <div class="mb-4 p-3 bg-yellow-50 border border-yellow-300 rounded text-yellow-800 text-sm">
            Advisory-only. All posture scores and finding diffs are informational.
            No active scanning, no automated response, no incident creation.
        </div>

        {{-- Risk score card --}}
        @if($riskScore)
        <div class="bg-white shadow rounded-lg p-4 mb-6 flex flex-wrap gap-6">
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wide">Current Score</div>
                <div class="text-3xl font-bold text-gray-900">{{ $riskScore->risk_score }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wide">Risk Tier</div>
                <div class="text-lg font-semibold capitalize
                    @if($riskScore->risk_tier === 'critical') text-red-600
                    @elseif($riskScore->risk_tier === 'high') text-orange-600
                    @elseif($riskScore->risk_tier === 'medium') text-yellow-600
                    @else text-green-600
                    @endif">
                    {{ $riskScore->risk_tier }}
                </div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wide">Trend</div>
                <div class="text-lg font-semibold capitalize
                    @if($riskScore->trend_direction === 'improving') text-green-600
                    @elseif($riskScore->trend_direction === 'degrading') text-red-600
                    @else text-gray-600
                    @endif">
                    {{ $riskScore->trend_direction }}
                </div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wide">Total Scans</div>
                <div class="text-lg font-semibold text-gray-900">{{ $riskScore->total_scans }}</div>
            </div>
            @if($riskScore->previous_score !== null)
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wide">Previous Score</div>
                <div class="text-lg font-semibold text-gray-700">{{ $riskScore->previous_score }}</div>
            </div>
            @endif
        </div>
        @else
        <div class="mb-6 p-4 bg-gray-50 border rounded text-gray-600 text-sm">
            No risk score computed yet. Run a scan to populate posture history.
        </div>
        @endif

        {{-- Snapshot table --}}
        <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
            <div class="px-4 py-3 border-b bg-gray-50 text-sm font-medium text-gray-700">
                Posture Snapshots (last 10 scans)
            </div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Snapshot At</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Score</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tier</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Findings</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">New</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Resolved</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Regressed</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    @forelse($snapshots as $snap)
                    <tr>
                        <td class="px-4 py-2 text-gray-700">{{ $snap->snapshot_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-2 font-semibold text-gray-900">{{ $snap->posture_score }}</td>
                        <td class="px-4 py-2 capitalize
                            @if($snap->risk_tier === 'critical') text-red-600
                            @elseif($snap->risk_tier === 'high') text-orange-600
                            @elseif($snap->risk_tier === 'medium') text-yellow-600
                            @else text-green-600
                            @endif">{{ $snap->risk_tier }}</td>
                        <td class="px-4 py-2 text-gray-700">{{ $snap->finding_count_total }}</td>
                        <td class="px-4 py-2 text-blue-600">{{ $snap->new_findings }}</td>
                        <td class="px-4 py-2 text-green-600">{{ $snap->resolved_findings }}</td>
                        <td class="px-4 py-2 text-red-600">{{ $snap->regressed_findings }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 text-xs rounded-full
                                @if($snap->overall_status === 'FINDINGS') bg-red-100 text-red-700
                                @elseif($snap->overall_status === 'WARN') bg-yellow-100 text-yellow-700
                                @else bg-green-100 text-green-700
                                @endif">
                                {{ $snap->overall_status }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-gray-400">No snapshots yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Actions --}}
        <div class="flex gap-3">
            <a href="{{ route('soc.easm.show', $asset->id) }}"
               class="px-4 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 text-sm">
                ← Asset Detail
            </a>
            <a href="{{ route('soc.easm.report', $asset->id) }}"
               class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm">
                Download JSON Report
            </a>
        </div>

    </div>
</x-app-layout>
