<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Enterprise Operations Dashboard</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-6 text-sm text-yellow-800">
            advisory-only — Enterprise operations workflows are bounded, replay-safe, and advisory-only.
            No autonomous remediation, destructive infrastructure mutation, or uncontrolled failover is executed.
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['recovery_runs'] }}</div>
                <div class="text-gray-500 text-sm">Recovery Runs</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-red-600">{{ $stats['failed_recoveries'] }}</div>
                <div class="text-gray-500 text-sm">Failed Recoveries</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['lifecycle_events'] }}</div>
                <div class="text-gray-500 text-sm">Lifecycle Events</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-orange-600">{{ $stats['drift_events'] }}</div>
                <div class="text-gray-500 text-sm">Drift Events</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-orange-600">{{ $stats['checkpoint_failures'] }}</div>
                <div class="text-gray-500 text-sm">Checkpoint Failures</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['failover_runs'] }}</div>
                <div class="text-gray-500 text-sm">Failover Runs</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-red-600">{{ $stats['continuity_failures'] }}</div>
                <div class="text-gray-500 text-sm">Continuity Failures</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['audit_events'] }}</div>
                <div class="text-gray-500 text-sm">Audit Events</div>
            </div>
        </div>
    </div>
</x-app-layout>
