<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Queue &amp; Stream Health Monitor</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Operational hardening and recovery tooling only. No autonomous infrastructure mutation.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-6xl mx-auto space-y-6">
        @if($degraded->count() > 0)
        <div class="rounded border border-red-400/30 bg-red-900/10 px-4 py-3 text-sm text-red-300">
            {{ $degraded->count() }} service(s) in degraded or down state. Review warnings timeline.
        </div>
        @endif

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Consumer Queue Lag</h3>
            @if($lag->isEmpty())
                <p class="text-xs text-gray-500">No lag metrics recorded.</p>
            @else
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-left text-gray-300">
                <thead class="text-gray-400 uppercase border-b border-gray-700">
                    <tr><th class="py-2 pr-3">Consumer Group</th><th class="py-2 pr-3">Topic</th><th class="py-2 pr-3">Lag</th><th class="py-2 pr-3">Age (s)</th><th class="py-2">Trend</th></tr>
                </thead>
                <tbody>
                    @foreach($lag as $l)
                    <tr class="border-b border-gray-800 @if($l->lag_count > 10000) bg-red-900/10 @elseif($l->lag_count > 1000) bg-amber-900/10 @endif">
                        <td class="py-1.5 pr-3 font-mono">{{ $l->consumer_group }}</td>
                        <td class="py-1.5 pr-3">{{ $l->topic }}</td>
                        <td class="py-1.5 pr-3 font-bold @if($l->lag_count > 10000) text-red-400 @elseif($l->lag_count > 1000) text-amber-400 @else text-gray-300 @endif">{{ number_format($l->lag_count) }}</td>
                        <td class="py-1.5 pr-3">{{ $l->lag_age_seconds }}</td>
                        <td class="py-1.5">
                            <span class="{{ $l->trend === 'increasing' ? 'text-red-400' : ($l->trend === 'decreasing' ? 'text-green-400' : 'text-gray-400') }}">
                                {{ $l->trend }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @endif
        </div>

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Stream Pressure Metrics</h3>
            @if($pressure->isEmpty())
                <p class="text-xs text-gray-500">No pressure metrics recorded.</p>
            @else
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-left text-gray-300">
                <thead class="text-gray-400 uppercase border-b border-gray-700">
                    <tr><th class="py-2 pr-3">Service</th><th class="py-2 pr-3">Metric</th><th class="py-2 pr-3">Value</th><th class="py-2">State</th></tr>
                </thead>
                <tbody>
                    @foreach($pressure as $p)
                    <tr class="border-b border-gray-800">
                        <td class="py-1.5 pr-3 font-mono">{{ $p->service_name }}</td>
                        <td class="py-1.5 pr-3">{{ str_replace('_', ' ', $p->metric_type) }}</td>
                        <td class="py-1.5 pr-3">{{ number_format($p->value, 1) }} {{ $p->unit }}</td>
                        <td class="py-1.5">
                            <span class="px-1.5 py-0.5 rounded text-xs
                                {{ $p->state === 'normal' ? 'bg-green-900/40 text-green-300' : ($p->state === 'warning' ? 'bg-amber-900/40 text-amber-300' : 'bg-red-900/40 text-red-300') }}">
                                {{ $p->state }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
