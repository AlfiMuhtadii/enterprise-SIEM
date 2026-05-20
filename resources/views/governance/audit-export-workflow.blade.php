<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Audit Export Workflow</h2>
        <p class="text-xs text-amber-400/80 mt-1">Governance and compliance workflows are audit-visible and replay-safe. No autonomous remediation or destructive evidence mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Export Rules:</strong> All exports require approval. Requester cannot self-approve. PII masking is enforced by default. Export integrity checksum generated on package.
        </div>

        @if($expiring->count() > 0)
        <div class="rounded border border-yellow-500/40 bg-yellow-900/10 px-4 py-3 text-sm text-yellow-300">
            <strong>⚠ Expiring Soon:</strong> {{ $expiring->count() }} export(s) expire within 7 days.
        </div>
        @endif

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Export Requests ({{ $requests->count() }})</h3>
            @forelse($requests as $req)
            <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                <div class="flex items-center gap-2">
                    <span class="font-mono text-gray-500">{{ $req->export_id }}</span>
                    <span class="text-cyan-300">{{ $req->scope }}</span>
                    <span class="px-1.5 py-0.5 rounded
                        @if($req->status === 'packaged') bg-green-900/40 text-green-300
                        @elseif($req->status === 'approved') bg-blue-900/40 text-blue-300
                        @elseif($req->status === 'pending_approval') bg-yellow-900/40 text-yellow-300
                        @elseif(in_array($req->status, ['rejected','expired'])) bg-red-900/40 text-red-300
                        @else bg-gray-700 text-gray-400 @endif">{{ $req->status }}</span>
                    @if($req->pii_masked)<span class="text-green-400">PII-MASKED</span>@endif
                    @if($req->integrity_checksum)<span class="text-teal-400">✓ CHECKSUM</span>@endif
                </div>
                <div class="text-gray-500 mt-0.5">{{ $req->export_format }} · expires: {{ $req->expires_at?->format('Y-m-d') ?? '—' }}</div>
            </div>
            @empty
            <p class="text-xs text-gray-500">No export requests.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
