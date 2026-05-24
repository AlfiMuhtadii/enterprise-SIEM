<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Architecture Explorer</h2>
    </x-slot>
    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> All demonstrations are synthetic, replay-safe, advisory-only, and bounded.
            No destructive exploitation or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow p-4 space-y-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-700">Platform</h3>
                <p class="text-sm text-gray-600 mt-1">{{ $summary['platform'] }}</p>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-700">Architecture Pattern</h3>
                <p class="text-sm text-gray-600 mt-1">{{ $summary['pattern'] }}</p>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-700">Active Correlation Scope</h3>
                <p class="text-sm text-green-700 font-medium mt-1">{{ $summary['active_scope'] }}</p>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-700">Shadow Scope (Advisory Only)</h3>
                <ul class="mt-1 space-y-1">
                    @foreach($summary['shadow_scope'] as $s)
                        <li class="text-sm text-yellow-700">• {{ $s }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="bg-gray-50 rounded p-3 text-center">
                    <div class="text-xl font-bold text-indigo-600">{{ $summary['detection_rules'] }}</div>
                    <div class="text-xs text-gray-500">Detection Rules</div>
                </div>
                <div class="bg-gray-50 rounded p-3 text-center">
                    <div class="text-xl font-bold text-indigo-600">{{ $summary['hunt_domains'] }}</div>
                    <div class="text-xs text-gray-500">Hunt Domains</div>
                </div>
                <div class="bg-gray-50 rounded p-3 text-center">
                    <div class="text-xl font-bold text-indigo-600">{{ count($summary['services']) }}</div>
                    <div class="text-xs text-gray-500">Microservices</div>
                </div>
                <div class="bg-gray-50 rounded p-3 text-center">
                    <div class="text-xl font-bold text-green-600">PASS</div>
                    <div class="text-xs text-gray-500">6h Soak (identity)</div>
                </div>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-700">Services</h3>
                <div class="flex flex-wrap gap-2 mt-2">
                    @foreach($summary['services'] as $svc)
                        <span class="bg-indigo-50 text-indigo-700 text-xs px-2 py-1 rounded">{{ $svc }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
