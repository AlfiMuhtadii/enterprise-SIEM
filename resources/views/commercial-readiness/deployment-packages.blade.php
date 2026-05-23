<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Deployment Package Viewer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-blue-50 border border-blue-300 rounded p-3 mb-6 text-sm text-blue-800">
            Commercial readiness workflows are bounded, replay-safe, and audit-visible. No destructive support action, hidden telemetry collection, or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Profile ID</th>
                        <th class="px-4 py-2 text-left">Name</th>
                        <th class="px-4 py-2 text-left">Environment</th>
                        <th class="px-4 py-2 text-left">Tier</th>
                        <th class="px-4 py-2 text-left">Version</th>
                        <th class="px-4 py-2 text-left">Checksum</th>
                        <th class="px-4 py-2 text-left">Compatible</th>
                        <th class="px-4 py-2 text-left">Active</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($profiles as $profile)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $profile->package_profile_id }}</td>
                        <td class="px-4 py-2">{{ $profile->profile_name }}</td>
                        <td class="px-4 py-2">{{ $profile->environment }}</td>
                        <td class="px-4 py-2">{{ $profile->deployment_tier }}</td>
                        <td class="px-4 py-2">{{ $profile->package_version }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs {{ $profile->checksum_verified ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                {{ $profile->checksum_verified ? 'Verified' : 'Pending' }}
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ $profile->compatibility_verified ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2">{{ $profile->is_active ? 'Active' : 'Inactive' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $profiles->links() }}</div>
    </div>
</x-app-layout>
