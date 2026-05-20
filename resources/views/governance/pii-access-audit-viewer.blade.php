<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">PII Access Audit Viewer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Governance and compliance workflows are audit-visible and replay-safe. No autonomous remediation or destructive evidence mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        @if($unmasked->count() > 0)
        <div class="rounded border border-red-500/40 bg-red-900/10 px-4 py-3 text-sm text-red-300">
            <strong>⚠ Unmasked PII Access:</strong> {{ $unmasked->count() }} access event(s) without masking applied.
        </div>
        @endif

        <div class="grid grid-cols-2 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Access by Category</h3>
                @foreach($byCategory as $cat => $catItems)
                <div class="flex items-center justify-between py-1 text-xs">
                    <span class="text-cyan-300">{{ $cat }}</span>
                    <span class="text-gray-300">{{ $catItems->count() }}</span>
                </div>
                @endforeach
                @if($accesses->isEmpty())<p class="text-xs text-gray-500">No PII access events.</p>@endif
            </div>
            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Recent Events ({{ $accesses->count() }})</h3>
                <div class="space-y-1 max-h-64 overflow-y-auto">
                    @forelse($accesses->take(30) as $a)
                    <div class="flex items-center gap-2 py-1 text-xs border-b border-gray-800 last:border-0">
                        <span class="text-gray-400 w-20 truncate">{{ $a->field_name }}</span>
                        <span class="text-cyan-300 w-20">{{ $a->pii_category }}</span>
                        @if($a->was_masked)
                        <span class="text-green-400">masked</span>
                        @else
                        <span class="text-red-400 font-bold">UNMASKED</span>
                        @endif
                        <span class="text-gray-500 ml-auto">{{ $a->access_context }}</span>
                    </div>
                    @empty
                    <p class="text-xs text-gray-500">No events.</p>
                    @endforelse
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
