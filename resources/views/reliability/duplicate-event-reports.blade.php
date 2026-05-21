<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Duplicate Event Reports</h2>
        <p class="text-xs text-amber-400/80 mt-1">Reliability controls are operational safeguards only. No autonomous remediation, destructive replay, or hidden data deletion is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-xs">
            @foreach(['informational', 'warning', 'critical'] as $sev)
            <div class="rounded border border-gray-700/50 bg-gray-900/40 px-4 py-3">
                <div class="text-gray-400 capitalize">{{ $sev }}</div>
                <div class="text-2xl font-bold @if($sev === 'critical') text-red-300 @elseif($sev === 'warning') text-yellow-300 @else text-gray-300 @endif">
                    {{ $bySev->get($sev)?->count() ?? 0 }}
                </div>
            </div>
            @endforeach
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Reports ({{ $reports->count() }})</h3>
            @forelse($reports as $r)
            <div class="py-2 border-b border-gray-800 last:border-0 text-xs">
                <div class="flex items-center gap-2">
                    <span class="px-1.5 py-0.5 rounded
                        @if($r->severity === 'critical') bg-red-900/40 text-red-300
                        @elseif($r->severity === 'warning') bg-yellow-900/40 text-yellow-300
                        @else bg-gray-700 text-gray-400 @endif">{{ $r->severity }}</span>
                    <span class="text-gray-400">{{ $r->source_topic }}</span>
                    <span class="font-mono text-gray-500">trace:{{ substr($r->trace_id, 0, 12) }}…</span>
                    @if($r->analytics_protected)<span class="text-green-400 text-xs">analytics-protected</span>@endif
                </div>
                @if($r->detection_reason)
                <div class="text-gray-500 mt-0.5 pl-1">{{ $r->detection_reason }}</div>
                @endif
            </div>
            @empty
            <p class="text-xs text-gray-500">No duplicate event reports.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
