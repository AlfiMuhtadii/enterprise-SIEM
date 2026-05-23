<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Enterprise Scale Dashboard</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-indigo-50 border border-indigo-300 rounded p-3 mb-6 text-sm text-indigo-800">
            Enterprise-scale governance workflows are bounded, replay-safe, and advisory-only. No uncontrolled failover, destructive infrastructure orchestration, or autonomous remediation is executed.
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['cluster_topology_reports'] }}</div>
                <div class="text-gray-500 text-sm">Topology Reports</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-green-600">{{ $stats['healthy_clusters'] }}</div>
                <div class="text-gray-500 text-sm">Healthy Clusters</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['ha_validation_runs'] }}</div>
                <div class="text-gray-500 text-sm">HA Validations</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-green-600">{{ $stats['ha_passing'] }}</div>
                <div class="text-gray-500 text-sm">HA Passing</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-green-600">{{ $stats['balanced_clusters'] }}</div>
                <div class="text-gray-500 text-sm">Balanced Clusters</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['failover_drills'] }}</div>
                <div class="text-gray-500 text-sm">Failover Drills</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['cost_reports'] }}</div>
                <div class="text-gray-500 text-sm">Cost Reports</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-green-600">{{ $stats['scale_passed'] }}</div>
                <div class="text-gray-500 text-sm">Scale Profiles Passed</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['audit_events'] }}</div>
                <div class="text-gray-500 text-sm">Audit Events</div>
            </div>
        </div>
        <div class="mt-6 grid grid-cols-3 gap-4 text-sm">
            <a href="{{ route('enterprise-scale.topology') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Cluster Topology Explorer &rarr;</a>
            <a href="{{ route('enterprise-scale.ha') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">HA Governance Viewer &rarr;</a>
            <a href="{{ route('enterprise-scale.distribution') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Telemetry Distribution &rarr;</a>
            <a href="{{ route('enterprise-scale.failover') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Failover Coordination &rarr;</a>
            <a href="{{ route('enterprise-scale.cost') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Infrastructure Cost &rarr;</a>
            <a href="{{ route('enterprise-scale.replay') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Replay Continuity &rarr;</a>
            <a href="{{ route('enterprise-scale.scale') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Scale Validation &rarr;</a>
            <a href="{{ route('enterprise-scale.audit') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Audit Timeline &rarr;</a>
        </div>
    </div>
</x-app-layout>
