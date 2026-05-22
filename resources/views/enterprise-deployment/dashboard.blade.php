<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Enterprise Deployment Dashboard</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-6 text-sm text-yellow-800">
            advisory-only — Deployment governance workflows are bounded, replay-safe, and approval-aware.
            No destructive rollout, hidden configuration mutation, or autonomous remediation is executed.
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['total_manifests'] }}</div>
                <div class="text-gray-500 text-sm">Package Manifests</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-red-600">{{ $stats['unsigned_packages'] }}</div>
                <div class="text-gray-500 text-sm">Unsigned Packages</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-red-600">{{ $stats['integrity_failures'] }}</div>
                <div class="text-gray-500 text-sm">Integrity Failures</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['rollout_runs'] }}</div>
                <div class="text-gray-500 text-sm">Rollout Runs</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-orange-600">{{ $stats['rollout_failures'] }}</div>
                <div class="text-gray-500 text-sm">Rollout Failures</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-red-600">{{ $stats['critical_drift'] }}</div>
                <div class="text-gray-500 text-sm">Critical Drift</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-orange-600">{{ $stats['checkpoint_failures'] }}</div>
                <div class="text-gray-500 text-sm">Checkpoint Failures</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['audit_events'] }}</div>
                <div class="text-gray-500 text-sm">Audit Events</div>
            </div>
        </div>
    </div>
</x-app-layout>
