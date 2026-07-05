<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Asset Inventory — CMDB & Advisory Criticality</h2>
        <p class="text-xs text-amber-400/80 mt-1">Advisory alert-enrichment metadata only. Criticality ranks the analyst queue — it never triggers or gates any response action.</p>
    </x-slot>

    @if (session('status'))
        <div class="mx-4 mt-4 max-w-7xl mx-auto rounded-lg border border-cyan-200/20 bg-cyan-100/10 p-3 text-sm text-cyan-50">{{ session('status') }}</div>
    @endif

    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            advisory-only — asset criticality is used only to rank the analyst queue. No response coupling.
        </div>

        <div class="grid gap-4 lg:grid-cols-4">
            <div class="metric-card"><p class="text-sm text-cyan-200/75">Total Assets</p><p class="mt-2 text-2xl text-main-ui">{{ $stats['total_assets'] }}</p></div>
            <div class="metric-card"><p class="text-sm text-cyan-200/75">Crown Jewel</p><p class="mt-2 text-2xl text-main-ui">{{ $stats['crown_jewel_count'] }}</p></div>
            <div class="metric-card"><p class="text-sm text-cyan-200/75">Environments</p><p class="mt-2 text-2xl text-main-ui">{{ $stats['by_environment']->count() }}</p></div>
            <div class="metric-card"><p class="text-sm text-cyan-200/75">Tenant</p><p class="mt-2 text-2xl text-main-ui">{{ $tenantId }}</p></div>
        </div>

        @if (in_array(Auth::user()?->role, ['admin', 'analyst'], true))
        <div class="grid gap-4 md:grid-cols-2">
            <section class="glass-card p-5">
                <h3 class="text-lg font-semibold text-main-ui">Register Asset</h3>
                <form method="POST" action="{{ route('asset-inventory.store') }}" class="mt-3 grid gap-2">
                    @csrf
                    <input name="hostname" placeholder="hostname" required class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                    <input name="ip_address" placeholder="ip address (optional)" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                    <input name="owner" placeholder="owner (optional)" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                    <select name="environment" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                        @foreach ($environments as $env)
                            <option value="{{ $env }}">{{ $env }}</option>
                        @endforeach
                    </select>
                    <select name="asset_type" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                        @foreach ($assetTypes as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                    <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Register</button>
                </form>
            </section>
            <section class="glass-card p-5">
                <h3 class="text-lg font-semibold text-main-ui">Bulk CSV Import</h3>
                <p class="mt-1 text-xs text-muted-ui">Columns: hostname, ip_address, owner, environment, asset_type, external_id</p>
                <form method="POST" action="{{ route('asset-inventory.import') }}" enctype="multipart/form-data" class="mt-3 grid gap-2">
                    @csrf
                    <input type="file" name="csv" accept=".csv,.txt" required class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                    <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Import</button>
                </form>
            </section>
        </div>
        @endif

        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Registered Assets (Tenant: {{ $tenantId }})</h3>
            @if ($assets->count() === 0)
                <p class="mt-3 text-xs text-muted-ui">No assets registered yet.</p>
            @else
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-xs text-cyan-50">
                    <thead><tr class="text-cyan-200/60 border-b border-cyan-200/20">
                        <th class="text-left py-1">Hostname</th>
                        <th class="text-left py-1">IP</th>
                        <th class="text-left py-1">Owner</th>
                        <th class="text-left py-1">Environment</th>
                        <th class="text-left py-1">Type</th>
                        <th class="text-left py-1">Criticality</th>
                        @if (in_array(Auth::user()?->role, ['admin', 'analyst'], true))
                        <th class="text-left py-1">Set Criticality</th>
                        @endif
                    </tr></thead>
                    <tbody>
                    @foreach ($assets as $asset)
                    <tr class="border-b border-cyan-200/10">
                        <td class="py-1 mono-ui">{{ $asset->hostname }}</td>
                        <td class="py-1 mono-ui">{{ $asset->ip_address ?: '-' }}</td>
                        <td class="py-1">{{ $asset->owner ?: '-' }}</td>
                        <td class="py-1">{{ $asset->environment }}</td>
                        <td class="py-1">{{ $asset->asset_type }}</td>
                        <td class="py-1">{{ $asset->criticality->criticality_tier ?? 'unrated' }}</td>
                        @if (in_array(Auth::user()?->role, ['admin', 'analyst'], true))
                        <td class="py-1">
                            <form method="POST" action="{{ route('asset-inventory.criticality', $asset->id) }}" class="flex gap-1">
                                @csrf
                                <select name="criticality_tier" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-1 text-xs text-cyan-50">
                                    @foreach ($tiers as $tier)
                                        <option value="{{ $tier }}" @selected(($asset->criticality->criticality_tier ?? null) === $tier)>{{ $tier }}</option>
                                    @endforeach
                                </select>
                                <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-2 py-1 text-xs text-cyan-50">Set</button>
                            </form>
                        </td>
                        @endif
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $assets->links() }}</div>
            @endif
        </section>
    </div>
</x-app-layout>
