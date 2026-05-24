<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Demo Walkthrough Console</h2>
    </x-slot>
    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> All demonstrations are synthetic, replay-safe, advisory-only, and bounded.
            No destructive exploitation or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow p-4 space-y-4">
            <h3 class="text-sm font-semibold text-gray-700">Evaluator Walkthrough Steps</h3>
            <ol class="space-y-3 text-sm text-gray-700 list-decimal list-inside">
                <li><strong>Start:</strong> Run <code class="bg-gray-100 px-1 rounded">.\bootstrap-dev.ps1</code> — seeds all demo fixtures, starts infrastructure</li>
                <li><strong>Readiness check:</strong> Visit <a href="{{ route('demo-platform.readiness') }}" class="text-indigo-600 hover:underline">Demo Readiness Dashboard</a> — verify overall_ready=true</li>
                <li><strong>Attack chain demo:</strong> Visit <a href="{{ route('demo-platform.scenarios') }}" class="text-indigo-600 hover:underline">Scenario Launcher</a> — review phishing_attack_chain run</li>
                <li><strong>Threat hunting:</strong> Visit <a href="/threat-hunts" class="text-indigo-600 hover:underline">Threat Hunts</a> — create a hunt on <code class="bg-gray-100 px-1 rounded">processes</code> domain</li>
                <li><strong>Detection quality:</strong> Visit <a href="/xdr-maturity/detection" class="text-indigo-600 hover:underline">Detection Scorecard</a> — review precision/recall metrics</li>
                <li><strong>Governance:</strong> Visit <a href="/xdr-certification" class="text-indigo-600 hover:underline">XDR Certification</a> — review acceptance gates</li>
                <li><strong>Capability matrix:</strong> Visit <a href="{{ route('demo-platform.capabilities') }}" class="text-indigo-600 hover:underline">Capability Matrix</a> — review honest capability tiers</li>
                <li><strong>Architecture:</strong> Visit <a href="{{ route('demo-platform.architecture') }}" class="text-indigo-600 hover:underline">Architecture Explorer</a> — review active vs shadow scope</li>
            </ol>
        </div>
        <div class="bg-white rounded shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Available Scenarios ({{ count($scenarios) }})</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                @foreach($scenarios as $s)
                    <div class="border rounded p-2 text-xs font-mono text-gray-700 bg-gray-50">{{ $s }}</div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
