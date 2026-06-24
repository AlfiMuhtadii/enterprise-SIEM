<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">EASM — External Attack Surface Monitoring</h2><p class="text-xs text-amber-400/80 mt-1">Passive posture monitoring only. Advisory-only findings. No active scanning, no exploitation, no containment.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            All EASM findings are advisory-only. Findings never create security incidents or modify detection rules. Scan policy is passive only.
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3">Registered Assets (Tenant: {{ $tenantId }})</h3>
            @if($assets->count() === 0)
                <p class="text-xs text-slate-500">No assets registered yet.</p>
            @else
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-slate-300">
                    <thead><tr class="text-slate-500 border-b border-slate-700">
                        <th class="text-left py-1">Name</th>
                        <th class="text-left py-1">Hostname</th>
                        <th class="text-left py-1">Scheme</th>
                        <th class="text-left py-1">Status</th>
                        <th class="text-left py-1">Policy</th>
                        <th class="text-left py-1">Last Scanned</th>
                        <th class="text-left py-1">Actions</th>
                    </tr></thead>
                    <tbody>
                    @foreach($assets as $asset)
                    <tr class="border-b border-slate-800">
                        <td class="py-1">{{ $asset->name }}</td>
                        <td class="py-1 font-mono text-xs">{{ $asset->hostname }}</td>
                        <td class="py-1">{{ $asset->scheme }}</td>
                        <td class="py-1 {{ $asset->status === 'active' ? 'text-green-400' : 'text-yellow-400' }}">{{ $asset->status }}</td>
                        <td class="py-1">{{ $asset->scan_policy }}</td>
                        <td class="py-1">{{ $asset->last_scanned_at?->diffForHumans() ?? 'never' }}</td>
                        <td class="py-1">
                            <a href="{{ route('soc.easm.show', $asset->id) }}" class="text-cyan-400 hover:underline">view</a>
                        </td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $assets->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
