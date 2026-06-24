<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            EASM — Tenant Posture Summary
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        {{-- Advisory banner --}}
        <div class="mb-4 p-3 bg-yellow-50 border border-yellow-300 rounded text-yellow-800 text-sm">
            Advisory-only. All posture data is informational. No active scanning, no automated response.
        </div>

        {{-- Summary cards --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white shadow rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-gray-900">{{ $summary['asset_count'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Assets</div>
            </div>
            <div class="bg-white shadow rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-gray-900">{{ $summary['open_findings'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Open Findings</div>
            </div>
            <div class="bg-white shadow rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-red-600">{{ $summary['by_severity']['high'] ?? 0 }}</div>
                <div class="text-xs text-gray-500 mt-1">High Severity</div>
            </div>
            <div class="bg-white shadow rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-orange-600">{{ $summary['by_severity']['medium'] ?? 0 }}</div>
                <div class="text-xs text-gray-500 mt-1">Medium Severity</div>
            </div>
        </div>

        {{-- Highest risk asset --}}
        @if($summary['highest_risk_asset'])
        <div class="bg-white shadow rounded-lg p-4 mb-6">
            <div class="text-sm font-medium text-gray-700 mb-2">Highest Risk Asset</div>
            <div class="flex gap-6 text-sm">
                <div><span class="text-gray-500">Asset ID:</span> <span class="font-semibold">{{ $summary['highest_risk_asset']['asset_id'] }}</span></div>
                <div><span class="text-gray-500">Score:</span> <span class="font-semibold">{{ $summary['highest_risk_asset']['risk_score'] }}</span></div>
                <div><span class="text-gray-500">Tier:</span>
                    <span class="font-semibold capitalize
                        @if($summary['highest_risk_asset']['risk_tier'] === 'critical') text-red-600
                        @elseif($summary['highest_risk_asset']['risk_tier'] === 'high') text-orange-600
                        @else text-yellow-600
                        @endif">
                        {{ $summary['highest_risk_asset']['risk_tier'] }}
                    </span>
                </div>
                <div><span class="text-gray-500">Trend:</span>
                    <span class="capitalize
                        @if($summary['highest_risk_asset']['trend'] === 'improving') text-green-600
                        @elseif($summary['highest_risk_asset']['trend'] === 'degrading') text-red-600
                        @else text-gray-600
                        @endif">
                        {{ $summary['highest_risk_asset']['trend'] }}
                    </span>
                </div>
            </div>
        </div>
        @endif

        {{-- Asset risk table --}}
        <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
            <div class="px-4 py-3 border-b bg-gray-50 text-sm font-medium text-gray-700">
                Asset Risk Scores
            </div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Asset</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Hostname</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Score</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tier</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Trend</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Scans</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    @forelse($riskScores as $rs)
                    <tr>
                        <td class="px-4 py-2 text-gray-900 font-medium">{{ $rs->asset?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-600 font-mono text-xs">{{ $rs->asset?->hostname ?? '—' }}</td>
                        <td class="px-4 py-2 font-semibold text-gray-900">{{ $rs->risk_score }}</td>
                        <td class="px-4 py-2 capitalize
                            @if($rs->risk_tier === 'critical') text-red-600
                            @elseif($rs->risk_tier === 'high') text-orange-600
                            @elseif($rs->risk_tier === 'medium') text-yellow-600
                            @else text-green-600
                            @endif">{{ $rs->risk_tier }}</td>
                        <td class="px-4 py-2 capitalize
                            @if($rs->trend_direction === 'improving') text-green-600
                            @elseif($rs->trend_direction === 'degrading') text-red-600
                            @else text-gray-600
                            @endif">{{ $rs->trend_direction }}</td>
                        <td class="px-4 py-2 text-gray-700">{{ $rs->total_scans }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('soc.easm.history', $rs->asset_id) }}"
                               class="text-blue-600 hover:underline text-xs">History</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-gray-400">No risk scores computed yet. Run scans to populate.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Findings by severity --}}
        <div class="bg-white shadow rounded-lg p-4 mb-6">
            <div class="text-sm font-medium text-gray-700 mb-3">Open Findings by Severity</div>
            <div class="grid grid-cols-4 gap-4 text-center">
                @foreach(['high' => 'red', 'medium' => 'orange', 'low' => 'yellow', 'info' => 'blue'] as $sev => $color)
                <div class="border rounded p-3">
                    <div class="text-xl font-bold text-{{ $color }}-600">{{ $summary['by_severity'][$sev] ?? 0 }}</div>
                    <div class="text-xs text-gray-500 capitalize mt-1">{{ $sev }}</div>
                </div>
                @endforeach
            </div>
        </div>

        <div class="flex gap-3">
            <a href="{{ route('soc.easm.index') }}"
               class="px-4 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 text-sm">
                ← Asset List
            </a>
        </div>

    </div>
</x-app-layout>
