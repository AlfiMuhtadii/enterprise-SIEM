<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Compliance Reporting Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1">Governance and compliance workflows are audit-visible and replay-safe. No autonomous remediation or destructive evidence mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
            @foreach([
                ['Total Findings', $stats['total_review_findings'], 'text-cyan-300'],
                ['Critical Findings', $stats['critical_findings'], 'text-red-300'],
                ['Integrity Failures', $stats['total_integrity_failures'], 'text-orange-300'],
                ['Stale Reviews', $stats['stale_reviews'], 'text-yellow-300'],
                ['PII Access Events', $stats['total_pii_access_events'], 'text-purple-300'],
                ['Unmasked PII', $stats['unmasked_pii_accesses'], 'text-red-400'],
                ['Open Reviews', $stats['open_reviews'], 'text-blue-300'],
                ['Pending Exports', $stats['pending_exports'], 'text-gray-300'],
            ] as [$label, $value, $color])
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $value }}</div>
            </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-xs">
            <div class="rounded border border-green-700/30 bg-green-900/10 px-4 py-3">
                <div class="text-green-300 font-medium">Advisory Only</div>
                <div class="text-green-200 mt-1">{{ $stats['advisory_only'] ? 'YES' : 'NO (WARNING)' }}</div>
            </div>
            <div class="rounded border border-green-700/30 bg-green-900/10 px-4 py-3">
                <div class="text-green-300 font-medium">Autonomous Remediation</div>
                <div class="text-green-200 mt-1">{{ $stats['autonomous_remediation'] ? 'ENABLED (WARNING)' : 'DISABLED' }}</div>
            </div>
            <div class="rounded border border-green-700/30 bg-green-900/10 px-4 py-3">
                <div class="text-green-300 font-medium">Retention Versions</div>
                <div class="text-green-200 mt-1">{{ $stats['total_retention_versions'] }} total · {{ $stats['pending_retention_reviews'] }} pending</div>
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Recent Findings</h3>
            @forelse($findings as $f)
            <div class="flex items-center gap-2 py-1.5 border-b border-gray-800 last:border-0 text-xs">
                <span class="px-1.5 py-0.5 rounded
                    @if($f->severity === 'critical') bg-red-900/40 text-red-300
                    @elseif($f->severity === 'warning') bg-yellow-900/40 text-yellow-300
                    @else bg-gray-700 text-gray-400 @endif">{{ $f->severity }}</span>
                <span class="text-gray-300">{{ $f->finding_type }}</span>
                <span class="text-gray-500 truncate flex-1">{{ Str::limit($f->description, 80) }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No findings.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
