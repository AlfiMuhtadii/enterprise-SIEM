<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">MSSP Rollup</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Read-only, advisory. No autonomous cross-tenant action is ever taken from this view.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Rollups are strictly limited to explicitly linked child tenants for the selected parent — no unscoped cross-tenant query is ever run.
        </div>

        <div class="glass-card p-4 space-y-3">
            <form method="GET" action="{{ route('mssp.rollup') }}" class="flex gap-3 items-center">
                <label class="text-xs text-gray-400">Parent tenant</label>
                <select name="parent_tenant_id" class="rounded bg-gray-800 border-gray-700 text-xs text-gray-200" onchange="this.form.submit()">
                    <option value="">— select —</option>
                    @foreach($parentTenants as $pt)
                    <option value="{{ $pt }}" @selected($pt === $parentTenantId)>{{ $pt }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        @can('soc:mssp.hierarchy.manage')
        @if($parentTenantId !== '')
        <div class="glass-card p-4 space-y-3">
            <h3 class="text-sm font-semibold text-purple-200">Link a child tenant</h3>
            <form method="POST" action="{{ route('mssp.link') }}" class="flex gap-3">
                @csrf
                <input type="hidden" name="parent_tenant_id" value="{{ $parentTenantId }}">
                <input type="text" name="child_tenant_id" placeholder="child tenant_id" class="rounded bg-gray-800 border-gray-700 text-xs text-gray-200" required maxlength="255">
                <button type="submit" class="rounded bg-cyan-700/40 hover:bg-cyan-700/60 text-cyan-100 text-xs px-3 py-2">Link</button>
            </form>
        </div>
        @endif
        @endcan

        @if($parentTenantId !== '')
        <div class="glass-card p-4 space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">Rollup for {{ $parentTenantId }}</h3>
            @forelse($summary as $row)
            <div class="flex justify-between items-center text-xs text-gray-300 border-b border-gray-700/40 pb-1">
                <span class="font-mono text-purple-300">{{ $row['tenant_id'] }}</span>
                <span>{{ $row['alert_count'] }} alerts · {{ $row['incident_count'] }} incidents ({{ $row['open_incident_count'] }} open)</span>
                @can('soc:mssp.hierarchy.manage')
                <form method="POST" action="{{ route('mssp.unlink') }}">
                    @csrf
                    <input type="hidden" name="parent_tenant_id" value="{{ $parentTenantId }}">
                    <input type="hidden" name="child_tenant_id" value="{{ $row['tenant_id'] }}">
                    <button type="submit" class="text-red-300 hover:text-red-200">Unlink</button>
                </form>
                @endcan
            </div>
            @empty
            <p class="text-xs text-gray-500">No child tenants linked to this parent.</p>
            @endforelse
        </div>
        @endif

    </div>
</x-app-layout>
