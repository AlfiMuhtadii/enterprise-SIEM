<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Demo Platform Dashboard
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> All demonstrations are synthetic, replay-safe, advisory-only, and bounded.
            No destructive exploitation or autonomous remediation is executed.
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-indigo-600">{{ $stats['demo_runs'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Demo Runs</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['latest_overall_ready'] ? 'text-green-600' : 'text-red-500' }}">
                    {{ $stats['latest_overall_ready'] ? 'READY' : 'PENDING' }}
                </div>
                <div class="text-xs text-gray-500 mt-1">Platform Status</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['latest_php_tests']) }}</div>
                <div class="text-xs text-gray-500 mt-1">PHP Tests Passed</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-purple-600">{{ $stats['domain_count'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Hunt Domains</div>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-xl font-bold text-gray-700">{{ $stats['total_rules'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Detection Rules</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-xl font-bold text-gray-700">{{ $stats['showcase_exports'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Showcase Exports</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-xl font-bold text-gray-700">{{ $stats['readiness_snapshots'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Readiness Snapshots</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-xl font-bold text-gray-700">{{ $stats['completed_runs'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Completed Scenarios</div>
            </div>
        </div>

        <div class="bg-white rounded shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Demo Navigation</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
                <a href="{{ route('demo-platform.scenarios') }}" class="text-indigo-600 hover:underline">Scenario Launcher</a>
                <a href="{{ route('demo-platform.timeline') }}" class="text-indigo-600 hover:underline">Attack Timeline</a>
                <a href="{{ route('demo-platform.readiness') }}" class="text-indigo-600 hover:underline">Readiness Dashboard</a>
                <a href="{{ route('demo-platform.architecture') }}" class="text-indigo-600 hover:underline">Architecture Explorer</a>
                <a href="{{ route('demo-platform.capabilities') }}" class="text-indigo-600 hover:underline">Capability Matrix</a>
                <a href="{{ route('demo-platform.replay') }}" class="text-indigo-600 hover:underline">Replay Explorer</a>
                <a href="{{ route('demo-platform.walkthrough') }}" class="text-indigo-600 hover:underline">Walkthrough Console</a>
                <a href="{{ route('demo-platform.showcase') }}" class="text-indigo-600 hover:underline">Platform Showcase</a>
            </div>
        </div>

    </div>
</x-app-layout>
