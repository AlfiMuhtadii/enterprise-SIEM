<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Pilot Tenant Onboarding
            <span class="ml-2 text-sm font-normal text-yellow-600">ADVISORY ONLY</span>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded shadow p-4 text-center">
                    <div class="text-3xl font-bold text-gray-800">{{ $tenants->count() }}</div>
                    <div class="text-sm text-gray-500">Pilot Tenants</div>
                </div>
                <div class="bg-blue-50 rounded shadow p-4 text-center">
                    <div class="text-3xl font-bold text-blue-700">{{ $max_tenants }}</div>
                    <div class="text-sm text-gray-500">Max Tenants (Limit)</div>
                </div>
                <div class="bg-yellow-50 rounded shadow p-4 text-center">
                    <div class="text-3xl font-bold text-yellow-700">{{ $max_tenants - $tenants->count() }}</div>
                    <div class="text-sm text-gray-500">Capacity Remaining</div>
                </div>
            </div>

            <div class="mb-4 bg-blue-50 border border-blue-200 rounded p-4 text-sm">
                <p class="font-semibold">To onboard a new pilot tenant:</p>
                <code class="text-blue-800">php artisan tenant:onboard-pilot --tenant-name="Acme Corp" --dry-run</code>
            </div>

            @if($tenants->isEmpty())
                <div class="bg-yellow-50 border border-yellow-200 rounded p-6 text-center">
                    <p class="text-yellow-700 font-semibold">No pilot tenants onboarded yet.</p>
                    <p class="text-sm text-gray-500 mt-1">Run <code>php artisan tenant:onboard-pilot</code> to create the first pilot tenant.</p>
                </div>
            @else
                <div class="bg-white rounded shadow overflow-hidden">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="px-4 py-3 text-left">Tenant ID</th>
                                <th class="px-4 py-3 text-left">Name</th>
                                <th class="px-4 py-3 text-left">Type</th>
                                <th class="px-4 py-3 text-left">Status</th>
                                <th class="px-4 py-3 text-left">Strict Compat</th>
                                <th class="px-4 py-3 text-left">Backfill</th>
                                <th class="px-4 py-3 text-left">Onboarded At</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach($tenants as $t)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs">{{ $t->tenant_id }}</td>
                                <td class="px-4 py-2">{{ $t->tenant_name }}</td>
                                <td class="px-4 py-2">{{ $t->tenant_type }}</td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-0.5 rounded text-xs {{ $t->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">{{ $t->status }}</span>
                                </td>
                                <td class="px-4 py-2 text-center">{!! $t->strict_mode_compatible ? '<span class="text-green-600">✓</span>' : '<span class="text-gray-400">–</span>' !!}</td>
                                <td class="px-4 py-2 text-center">{!! $t->null_backfill_completed ? '<span class="text-green-600">✓</span>' : '<span class="text-yellow-500">⚠</span>' !!}</td>
                                <td class="px-4 py-2 text-xs text-gray-500">{{ $t->onboarded_at }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
