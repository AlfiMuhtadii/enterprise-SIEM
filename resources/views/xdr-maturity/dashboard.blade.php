<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Code-Level XDR Maturity Dashboard
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Code-level maturity workflows are synthetic, replay-safe, advisory-only, and bounded.
            No destructive execution, autonomous remediation, or real exploit activity is executed.
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-indigo-600">{{ $stats['total_fixtures'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Attack Fixtures</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-green-600">{{ number_format($stats['avg_detection_quality'] * 100, 1) }}%</div>
                <div class="text-xs text-gray-500 mt-1">Avg Detection Quality</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['avg_telemetry_quality'] * 100, 1) }}%</div>
                <div class="text-xs text-gray-500 mt-1">Avg Telemetry Quality</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['latest_maturity_score'] * 100, 1) }}%</div>
                <div class="text-xs text-gray-500 mt-1">Latest Maturity Score</div>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-xl font-bold text-gray-700">{{ $stats['detection_scorecards'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Detection Scorecards</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-xl font-bold text-gray-700">{{ $stats['fpfn_reports'] }}</div>
                <div class="text-xs text-gray-500 mt-1">FP/FN Reports</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-xl font-bold text-gray-700">{{ $stats['triage_simulations'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Triage Simulations</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-xl font-bold text-gray-700">{{ $stats['readiness_artifacts'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Readiness Artifacts</div>
            </div>
        </div>

        <div class="bg-white rounded shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Navigation</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
                <a href="{{ route('xdr-maturity.fixtures') }}" class="text-indigo-600 hover:underline">Fixture Explorer</a>
                <a href="{{ route('xdr-maturity.detection') }}" class="text-indigo-600 hover:underline">Detection Scorecard</a>
                <a href="{{ route('xdr-maturity.fpfn') }}" class="text-indigo-600 hover:underline">FP/FN Analysis</a>
                <a href="{{ route('xdr-maturity.telemetry') }}" class="text-indigo-600 hover:underline">Telemetry Quality</a>
                <a href="{{ route('xdr-maturity.triage') }}" class="text-indigo-600 hover:underline">Triage Simulation</a>
                <a href="{{ route('xdr-maturity.hotspots') }}" class="text-indigo-600 hover:underline">Hotspot Explorer</a>
                <a href="{{ route('xdr-maturity.noisy') }}" class="text-indigo-600 hover:underline">Noisy Simulation</a>
                <a href="{{ route('xdr-maturity.report') }}" class="text-indigo-600 hover:underline">Readiness Report</a>
            </div>
        </div>

    </div>
</x-app-layout>
