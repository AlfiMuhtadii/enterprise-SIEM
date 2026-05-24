<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Capability Matrix Viewer</h2>
    </x-slot>
    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> All demonstrations are synthetic, replay-safe, advisory-only, and bounded.
            No destructive exploitation or autonomous remediation is executed.
        </div>
        <div class="flex gap-4 text-xs">
            <span class="bg-green-100 text-green-700 px-2 py-1 rounded">implemented</span>
            <span class="bg-yellow-100 text-yellow-700 px-2 py-1 rounded">advisory_only</span>
            <span class="bg-gray-100 text-gray-500 px-2 py-1 rounded">not_implemented</span>
        </div>
        @foreach($matrix as $category => $capabilities)
            <div class="bg-white rounded shadow overflow-x-auto">
                <div class="bg-gray-50 px-4 py-2 text-xs font-semibold text-gray-600 uppercase">{{ ucwords(str_replace('_', ' ', $category)) }}</div>
                <table class="min-w-full text-sm">
                    <thead class="text-xs text-gray-500 uppercase border-b">
                        <tr>
                            <th class="px-4 py-2 text-left">Capability</th>
                            <th class="px-4 py-2 text-left">Status</th>
                            <th class="px-4 py-2 text-left">Scope</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach($capabilities as $cap)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2">{{ $cap['capability'] }}</td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-0.5 rounded text-xs
                                        {{ $cap['status'] === 'implemented' ? 'bg-green-100 text-green-700' : ($cap['status'] === 'not_implemented' ? 'bg-gray-100 text-gray-500' : 'bg-yellow-100 text-yellow-700') }}">
                                        {{ $cap['status'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-xs text-gray-500">{{ $cap['scope'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</x-app-layout>
