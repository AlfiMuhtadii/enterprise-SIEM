<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Integrity Failure Timeline</h2>
        <p class="text-xs text-amber-400/80 mt-1">Governance and compliance workflows are audit-visible and replay-safe. No autonomous remediation or destructive evidence mutation is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-2 gap-4">
            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Failures by Type</h3>
                @foreach($byType as $type => $typeFailures)
                <div class="flex justify-between py-1 text-xs border-b border-gray-800 last:border-0">
                    <span class="text-orange-300">{{ $type }}</span>
                    <span class="text-gray-300">{{ $typeFailures->count() }}</span>
                </div>
                @endforeach
                @if($failures->isEmpty())<p class="text-xs text-gray-500">No integrity failures.</p>@endif
            </div>

            <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Recent Failures ({{ $failures->count() }})</h3>
                <div class="space-y-1 max-h-64 overflow-y-auto">
                    @forelse($failures->take(50) as $f)
                    <div class="py-1 border-b border-gray-800 last:border-0 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="text-orange-300">{{ $f->failure_type }}</span>
                            <span class="text-gray-400">{{ $f->evidence_type }}</span>
                            @if($f->drift_detected)<span class="text-red-400">DRIFT</span>@endif
                        </div>
                        @if($f->description)<div class="text-gray-500 mt-0.5">{{ Str::limit($f->description, 80) }}</div>@endif
                    </div>
                    @empty
                    <p class="text-xs text-gray-500">No failures.</p>
                    @endforelse
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
