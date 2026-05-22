<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Endpoint Enrollment Monitor</h2></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Pilot execution workflows are bounded, replay-safe, and advisory-only. No autonomous remediation, destructive rollback, or unrestricted onboarding is executed.
        </div>
        <div class="overflow-x-auto rounded border border-slate-700">
            <table class="w-full text-sm text-slate-300">
                <thead class="bg-slate-800 text-slate-400 text-xs uppercase">
                    <tr><th class="px-4 py-2">Enrollment ID</th><th>Hostname</th><th>Tenant</th><th>Status</th><th>Telemetry Cont.</th><th>Verified</th></tr>
                </thead>
                <tbody>
                @foreach($enrollments as $e)
                <tr class="border-t border-slate-700">
                    <td class="px-4 py-2 font-mono text-xs">{{ $e->enrollment_id }}</td>
                    <td>{{ $e->hostname }}</td>
                    <td>{{ $e->tenant_id }}</td>
                    <td>{{ $e->status }}</td>
                    <td>{{ number_format($e->telemetry_continuity_pct * 100, 1) }}%</td>
                    <td>{{ $e->onboarding_verified ? '✓' : '✗' }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $enrollments->links() }}
    </div>
</x-app-layout>
