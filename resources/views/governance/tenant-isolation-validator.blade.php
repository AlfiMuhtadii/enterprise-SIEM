<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Tenant Isolation Validator</h2>
        <p class="text-xs text-amber-400/80 mt-1">Governance and compliance workflows are audit-visible and replay-safe. No autonomous remediation or destructive evidence mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-3 gap-4 text-xs">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">Total Runs</div>
                <div class="text-2xl font-bold text-cyan-300">{{ $runs->count() }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">With Violations</div>
                <div class="text-2xl font-bold text-red-300">{{ $violations->count() }}</div>
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">Pass Rate</div>
                <div class="text-2xl font-bold text-green-300">
                    @if($runs->count() > 0){{ number_format($runs->where('status','passed')->count() / $runs->count() * 100, 1) }}%
                    @else 100.0% @endif
                </div>
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Validation Runs ({{ $runs->count() }})</h3>
            @forelse($runs as $run)
            <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                <div class="flex items-center gap-2">
                    <span class="text-cyan-300">{{ $run->tenant_scope }}</span>
                    <span class="text-gray-400">{{ $run->check_type }}</span>
                    <span class="px-1.5 py-0.5 rounded
                        @if($run->status === 'passed') bg-green-900/40 text-green-300
                        @elseif($run->status === 'warning') bg-yellow-900/40 text-yellow-300
                        @else bg-red-900/40 text-red-300 @endif">{{ $run->status }}</span>
                    @if($run->cross_tenant_detected)<span class="text-red-400 font-bold">CROSS-TENANT</span>@endif
                    @if($run->violations_found > 0)<span class="text-orange-400">{{ $run->violations_found }} violation(s)</span>@endif
                </div>
            </div>
            @empty
            <p class="text-xs text-gray-500">No isolation validation runs.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
