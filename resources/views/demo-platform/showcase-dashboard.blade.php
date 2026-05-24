<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Final Platform Showcase Dashboard</h2>
    </x-slot>
    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> All demonstrations are synthetic, replay-safe, advisory-only, and bounded.
            No destructive exploitation or autonomous remediation is executed.
        </div>
        <div class="bg-indigo-50 border border-indigo-200 rounded p-4">
            <h3 class="text-sm font-semibold text-indigo-800">Hybrid Near Real-Time Web Attack Detection Platform</h3>
            <p class="text-xs text-indigo-700 mt-1">Rule-Based Detection + Multiclass Logistic Regression within an Event-Driven Investigation Architecture</p>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-green-600">{{ number_format($stats['latest_php_tests']) }}</div>
                <div class="text-xs text-gray-500 mt-1">PHP Tests</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-blue-600">{{ $stats['total_rules'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Detection Rules</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-purple-600">{{ $stats['domain_count'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Hunt Domains</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-indigo-600">{{ $stats['demo_runs'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Demo Scenarios</div>
            </div>
        </div>
        <div class="bg-white rounded shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Capability Summary</h3>
            @foreach($matrix as $category => $caps)
                <div class="mb-2">
                    <span class="text-xs font-semibold text-gray-600 uppercase">{{ ucwords(str_replace('_', ' ', $category)) }}</span>
                    <span class="ml-2 text-xs text-gray-400">({{ count(array_filter($caps, fn($c) => $c['status'] === 'implemented')) }} implemented, {{ count(array_filter($caps, fn($c) => $c['status'] === 'advisory_only')) }} advisory)</span>
                </div>
            @endforeach
        </div>
        <div class="bg-white rounded shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Recent Exports</h3>
            <table class="min-w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase border-b">
                    <tr>
                        <th class="px-3 py-2 text-left">Type</th>
                        <th class="px-3 py-2 text-left">Format</th>
                        <th class="px-3 py-2 text-left">Deterministic</th>
                        <th class="px-3 py-2 text-left">Fabricated</th>
                        <th class="px-3 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($exports as $e)
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2">{{ $e->export_type }}</td>
                            <td class="px-3 py-2">{{ $e->export_format }}</td>
                            <td class="px-3 py-2 text-green-600">{{ $e->is_deterministic ? 'Yes' : 'No' }}</td>
                            <td class="px-3 py-2 text-green-600">{{ $e->is_fabricated ? 'Yes' : 'No' }}</td>
                            <td class="px-3 py-2 text-xs text-gray-400">{{ $e->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-4 text-center text-gray-400 text-sm">No exports yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
