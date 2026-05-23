<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Commercial Readiness Dashboard</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-blue-50 border border-blue-300 rounded p-3 mb-6 text-sm text-blue-800">
            Commercial readiness workflows are bounded, replay-safe, and audit-visible. No destructive support action, hidden telemetry collection, or autonomous remediation is executed.
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['onboarding_runs'] }}</div>
                <div class="text-gray-500 text-sm">Onboarding Runs</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-green-600">{{ $stats['active_packages'] }}</div>
                <div class="text-gray-500 text-sm">Active Packages</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-blue-600">{{ $stats['releases_published'] }}</div>
                <div class="text-gray-500 text-sm">Releases Published</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['support_exports'] }}</div>
                <div class="text-gray-500 text-sm">Support Exports</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-green-600">{{ $stats['deployments_ready'] }}</div>
                <div class="text-gray-500 text-sm">Deployments Ready</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold text-green-600">{{ $stats['healthy_tenants'] }}</div>
                <div class="text-gray-500 text-sm">Healthy Tenants</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['readiness_reports'] }}</div>
                <div class="text-gray-500 text-sm">Readiness Reports</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['compatibility_reports'] }}</div>
                <div class="text-gray-500 text-sm">Compatibility Reports</div>
            </div>
            <div class="bg-white rounded shadow p-4">
                <div class="text-2xl font-bold">{{ $stats['audit_events'] }}</div>
                <div class="text-gray-500 text-sm">Audit Events</div>
            </div>
        </div>
        <div class="mt-6 grid grid-cols-3 gap-4 text-sm">
            <a href="{{ route('commercial.onboarding') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Tenant Onboarding Explorer &rarr;</a>
            <a href="{{ route('commercial.packages') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Deployment Package Viewer &rarr;</a>
            <a href="{{ route('commercial.releases') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Release Governance Timeline &rarr;</a>
            <a href="{{ route('commercial.readiness') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Deployment Readiness Console &rarr;</a>
            <a href="{{ route('commercial.support') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Support Diagnostics Dashboard &rarr;</a>
            <a href="{{ route('commercial.health') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Customer Operations Health &rarr;</a>
            <a href="{{ route('commercial.upgrade') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Upgrade Compatibility Explorer &rarr;</a>
            <a href="{{ route('commercial.audit') }}" class="bg-white rounded shadow p-3 hover:bg-gray-50">Commercial Product Audit &rarr;</a>
        </div>
    </div>
</x-app-layout>
