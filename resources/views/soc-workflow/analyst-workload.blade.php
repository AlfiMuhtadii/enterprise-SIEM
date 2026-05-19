<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Analyst Workload View</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Analyst-driven collaborative workflows only. No autonomous SOC operations.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-3 py-2 text-xs text-amber-300">
            Workload metrics are advisory. No auto-routing or reassignment based on workload data.
        </div>
        @forelse($workload as $w)
        @php $analyst = $analysts->firstWhere('id', $w->assigned_to); @endphp
        <div class="glass-card p-4">
            <div class="flex items-center justify-between mb-2">
                <div class="text-sm font-medium text-cyan-200">{{ $analyst?->name ?? 'Analyst #'.$w->assigned_to }}</div>
                <span class="text-sm font-bold {{ $w->open_count > 10 ? 'text-red-400' : ($w->open_count > 5 ? 'text-amber-400' : 'text-green-400') }}">
                    {{ $w->open_count }} open
                </span>
            </div>
            <div class="flex gap-4 text-xs text-gray-400">
                <span>Oldest: {{ $w->oldest_at ? \Carbon\Carbon::parse($w->oldest_at)->diffForHumans() : '—' }}</span>
                <span>Newest: {{ $w->newest_at ? \Carbon\Carbon::parse($w->newest_at)->diffForHumans() : '—' }}</span>
            </div>
            <div class="mt-2 h-2 bg-gray-800 rounded">
                <div class="h-2 rounded {{ $w->open_count > 10 ? 'bg-red-500' : ($w->open_count > 5 ? 'bg-amber-500' : 'bg-green-500') }}"
                     style="width: {{ min(100, $w->open_count * 10) }}%"></div>
            </div>
        </div>
        @empty
        <p class="text-sm text-gray-500">No active investigation assignments.</p>
        @endforelse
    </div>
</x-app-layout>
