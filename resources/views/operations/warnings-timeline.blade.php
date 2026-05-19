<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Operational Warnings Timeline</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Operational hardening and recovery tooling only. No autonomous infrastructure mutation.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            No autonomous remediation. All warnings require operator review and explicit action.
        </div>

        @if(empty($warnings))
            <p class="text-sm text-green-400">No active operational warnings.</p>
        @else
        <div class="space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">Active Warnings ({{ count($warnings) }})</h3>
            @foreach($warnings as $w)
            <div class="flex gap-4 items-start rounded border
                {{ $w['severity'] === 'critical' ? 'border-red-400/30 bg-red-900/10' : 'border-amber-400/20 bg-amber-900/10' }}
                px-3 py-2">
                <span class="shrink-0 font-mono text-xs uppercase {{ $w['severity'] === 'critical' ? 'text-red-400' : 'text-amber-400' }}">
                    {{ $w['severity'] }}
                </span>
                <div class="flex-1">
                    <div class="text-xs text-gray-300">{{ $w['message'] }}</div>
                    <div class="text-xs text-gray-500 mt-0.5">{{ $w['type'] }}</div>
                </div>
            </div>
            @endforeach
        </div>
        @endif

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Recent Retention Audit Events</h3>
            @forelse($audit as $a)
            <div class="flex gap-4 items-center py-1 border-b border-gray-800 text-xs">
                <span class="text-gray-500 w-20 shrink-0">{{ \Carbon\Carbon::parse($a->created_at)->format('H:i:s') }}</span>
                <span class="text-cyan-400">{{ str_replace('_', ' ', $a->event_type) }}</span>
                <span class="text-gray-400">{{ $a->domain }}</span>
                <span class="{{ $a->dry_run ? 'text-blue-400' : 'text-amber-400' }}">{{ $a->dry_run ? 'dry-run' : 'LIVE' }}</span>
                <span class="text-gray-500">{{ $a->row_count }} rows</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No audit events.</p>
            @endforelse
        </div>

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Service Health Summary</h3>
            @forelse($health as $h)
            <div class="flex items-center justify-between py-1 border-b border-gray-800 text-xs">
                <span class="font-mono text-gray-300">{{ $h->service_name }}</span>
                <span class="{{ $h->health_state === 'healthy' ? 'text-green-400' : ($h->health_state === 'degraded' ? 'text-amber-400' : 'text-red-400') }}">
                    {{ $h->health_state }}
                </span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No service health data.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
