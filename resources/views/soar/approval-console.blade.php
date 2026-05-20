<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">SOAR Approval Workflow Console</h2>
        <p class="text-xs text-amber-400/80 mt-1">SOAR workflows are simulation-first and approval-gated. No autonomous enforcement or destructive response is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Approval Rules:</strong> Creator cannot self-approve. Simulation must be completed before approval. All decisions are audit-logged and append-only.
        </div>

        <div class="rounded border border-yellow-700/30 bg-yellow-900/10 p-4">
            <h3 class="text-sm font-semibold text-yellow-200 mb-3">Pending Approvals ({{ $pending->count() }})</h3>
            @forelse($pending as $req)
            <div class="flex items-center justify-between py-2 border-b border-gray-800 last:border-0 text-xs">
                <div>
                    <span class="text-cyan-300 font-medium font-mono">{{ $req->plan_id }}</span>
                    <span class="ml-2 text-gray-400">{{ $req->approval_type }}</span>
                    <span class="ml-2 text-gray-400">{{ $req->created_at?->format('Y-m-d H:i') ?? '—' }}</span>
                </div>
                <span class="px-1.5 py-0.5 rounded bg-yellow-900/40 text-yellow-300">{{ $req->decision }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No pending approvals.</p>
            @endforelse
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Recent Decisions ({{ $recent->count() }})</h3>
            @forelse($recent as $req)
            <div class="flex items-center justify-between py-2 border-b border-gray-800 last:border-0 text-xs">
                <div>
                    <span class="font-mono text-gray-400">{{ $req->plan_id }}</span>
                    <span class="ml-2 text-gray-400">{{ $req->approval_type }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-1.5 py-0.5 rounded
                        @if($req->decision === 'approved') bg-green-900/40 text-green-300
                        @else bg-red-900/40 text-red-300 @endif">{{ $req->decision }}</span>
                    <span class="text-gray-500">{{ $req->decided_at?->format('Y-m-d H:i') ?? '—' }}</span>
                </div>
            </div>
            @empty
            <p class="text-xs text-gray-500">No recent decisions.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
