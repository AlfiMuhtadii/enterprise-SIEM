<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Evidence Integrity Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Governance and compliance workflows are audit-visible and replay-safe. No autonomous remediation or destructive evidence mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Governance and compliance workflows are audit-visible and replay-safe. No autonomous remediation or destructive evidence mutation is executed.
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('governance.retention') }}" class="px-3 py-1.5 text-xs rounded bg-blue-900/30 text-blue-300 border border-blue-700/40 hover:bg-blue-900/50">Retention Governance</a>
            <a href="{{ route('governance.export-workflow') }}" class="px-3 py-1.5 text-xs rounded bg-green-900/30 text-green-300 border border-green-700/40 hover:bg-green-900/50">Audit Export</a>
            <a href="{{ route('governance.tenant-isolation') }}" class="px-3 py-1.5 text-xs rounded bg-purple-900/30 text-purple-300 border border-purple-700/40 hover:bg-purple-900/50">Tenant Isolation</a>
            <a href="{{ route('governance.pii-audit') }}" class="px-3 py-1.5 text-xs rounded bg-red-900/30 text-red-300 border border-red-700/40 hover:bg-red-900/50">PII Access Audit</a>
            <a href="{{ route('governance.access-review') }}" class="px-3 py-1.5 text-xs rounded bg-yellow-900/30 text-yellow-300 border border-yellow-700/40 hover:bg-yellow-900/50">Access Reviews</a>
            <a href="{{ route('governance.compliance-report') }}" class="px-3 py-1.5 text-xs rounded bg-cyan-900/30 text-cyan-300 border border-cyan-700/40 hover:bg-cyan-900/50">Compliance Report</a>
            <a href="{{ route('governance.integrity-failures') }}" class="px-3 py-1.5 text-xs rounded bg-orange-900/30 text-orange-300 border border-orange-700/40 hover:bg-orange-900/50">Failure Timeline</a>
            <a href="{{ route('governance.findings') }}" class="px-3 py-1.5 text-xs rounded bg-gray-800 text-gray-300 border border-gray-600 hover:bg-gray-700">Findings</a>
        </div>

        <div class="grid grid-cols-4 gap-4">
            @foreach([
                ['Integrity Runs', $stats['total_integrity_runs'], 'text-cyan-300'],
                ['Failed Runs', $stats['failed_integrity_runs'], 'text-red-300'],
                ['Integrity Failures', $stats['total_integrity_failures'], 'text-orange-300'],
                ['Critical Findings', $stats['critical_findings'], 'text-red-400'],
                ['Stale Reviews', $stats['stale_reviews'], 'text-yellow-400'],
                ['Pending Exports', $stats['pending_exports'], 'text-blue-300'],
                ['Unmasked PII', $stats['unmasked_pii_accesses'], 'text-red-300'],
                ['Open Reviews', $stats['open_reviews'], 'text-yellow-300'],
            ] as [$label, $value, $color])
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-xs text-gray-400">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ $value }}</div>
            </div>
            @endforeach
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Recent Integrity Runs ({{ $recentRuns->count() }})</h3>
            @forelse($recentRuns as $run)
            <div class="flex items-center justify-between py-1.5 border-b border-gray-800 last:border-0 text-xs">
                <div>
                    <span class="text-cyan-300">{{ $run->scope }}</span>
                    <span class="ml-2 font-mono text-gray-500">{{ $run->scope_id ?? '—' }}</span>
                    <span class="ml-2 px-1.5 py-0.5 rounded
                        @if($run->status === 'passed') bg-green-900/40 text-green-300
                        @elseif($run->status === 'failed') bg-red-900/40 text-red-300
                        @elseif($run->status === 'warning') bg-yellow-900/40 text-yellow-300
                        @else bg-blue-900/40 text-blue-300 @endif">{{ $run->status }}</span>
                </div>
                <span class="text-gray-500">{{ $run->created_at?->format('Y-m-d H:i') ?? '—' }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No integrity runs yet.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
