<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Real Endpoint Enrollments
            <span class="ml-2 text-sm font-normal text-yellow-600">ADVISORY ONLY — no active containment</span>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded shadow p-4 text-center">
                    <div class="text-3xl font-bold text-gray-800">{{ $summary['total'] }}</div>
                    <div class="text-sm text-gray-500">Enrolled Endpoints</div>
                </div>
                <div class="bg-green-50 rounded shadow p-4 text-center">
                    <div class="text-3xl font-bold text-green-700">{{ $summary['real_os_data'] }}</div>
                    <div class="text-sm text-gray-500">Real OS Data</div>
                </div>
                <div class="bg-blue-50 rounded shadow p-4 text-center">
                    <div class="text-3xl font-bold text-blue-700">{{ $summary['heartbeat_active'] }}</div>
                    <div class="text-sm text-gray-500">Heartbeat Active</div>
                </div>
                <div class="bg-gray-50 rounded shadow p-4 text-center">
                    <div class="text-3xl font-bold text-gray-600">{{ $summary['max_enrollments'] }}</div>
                    <div class="text-sm text-gray-500">Max Capacity</div>
                </div>
            </div>

            <div class="mb-4 bg-blue-50 border border-blue-200 rounded p-4 text-sm">
                <p class="font-semibold">To enroll a real endpoint:</p>
                <code class="text-blue-800">python scripts/run_real_endpoint_enrollment.py --dry-run</code>
                <br>
                <code class="text-blue-800">php artisan endpoint:verify-enrollment --list</code>
            </div>

            @if($enrollments->isEmpty())
                <div class="bg-yellow-50 border border-yellow-200 rounded p-6 text-center">
                    <p class="text-yellow-700 font-semibold">No real endpoints enrolled yet.</p>
                    <p class="text-sm text-gray-500 mt-1">Run the enrollment demo script to register this host.</p>
                </div>
            @else
                <div class="bg-white rounded shadow overflow-hidden">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="px-4 py-3 text-left">Hostname</th>
                                <th class="px-4 py-3 text-left">OS</th>
                                <th class="px-4 py-3 text-left">Tenant</th>
                                <th class="px-4 py-3 text-left">Processes</th>
                                <th class="px-4 py-3 text-left">Real Data</th>
                                <th class="px-4 py-3 text-left">Enrolled</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach($enrollments as $e)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs">{{ $e->hostname }}</td>
                                <td class="px-4 py-2">{{ $e->os_platform }}</td>
                                <td class="px-4 py-2 text-xs text-gray-500">{{ $e->tenant_id ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $e->process_count }}</td>
                                <td class="px-4 py-2 text-center">{!! $e->is_real ? '<span class="text-green-600">✓</span>' : '<span class="text-gray-400">–</span>' !!}</td>
                                <td class="px-4 py-2 text-xs text-gray-500">{{ $e->enrolled_at }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
