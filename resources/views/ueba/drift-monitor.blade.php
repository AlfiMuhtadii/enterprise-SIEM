<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Baseline Drift Monitor</h2>
        <p class="text-xs text-amber-400/80 mt-1">Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory Notice:</strong> Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.
        </div>

        <div class="flex justify-end">
            <a href="{{ route('ueba.dashboard') }}" class="text-xs text-cyan-400 hover:underline">← UEBA Dashboard</a>
        </div>

        {{-- Per-dimension summary --}}
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Drift Coverage by Dimension</h3>
            <div class="flex flex-wrap gap-2">
                @foreach($dimensions as $dim)
                    @php $count = $byDimension[$dim] ?? 0; @endphp
                    <div class="px-3 py-2 rounded border {{ $count > 0 ? 'border-yellow-700/40 bg-yellow-900/10' : 'border-gray-700 bg-gray-800/30' }} text-center min-w-[100px]">
                        <div class="font-bold {{ $count > 0 ? 'text-yellow-400' : 'text-gray-600' }}">{{ $count }}</div>
                        <div class="text-xs {{ $count > 0 ? 'text-gray-400' : 'text-gray-600' }}">{{ str_replace('_', ' ', $dim) }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Drift table --}}
        <div class="glass-card p-0 overflow-hidden">
            <div class="flex justify-between items-center px-4 py-3 border-b border-gray-700">
                <span class="text-sm font-semibold text-cyan-200">Highest Variance Baselines</span>
                <span class="text-xs text-gray-500">{{ $driftData->count() }} entries</span>
            </div>
            @if($driftData->isEmpty())
                <div class="text-center text-gray-500 text-sm py-8">No baseline drift data available yet.</div>
            @else
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-800/60 text-gray-400 uppercase tracking-wider">
                        <tr>
                            <th class="px-3 py-2 text-left">Entity Key</th>
                            <th class="px-3 py-2 text-left">Type</th>
                            <th class="px-3 py-2 text-left">Dimension</th>
                            <th class="px-3 py-2 text-right">Mean</th>
                            <th class="px-3 py-2 text-right">Std Dev</th>
                            <th class="px-3 py-2 text-center">Samples</th>
                            <th class="px-3 py-2 text-left">Computed</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                    @foreach($driftData as $d)
                        <tr class="hover:bg-gray-800/30">
                            <td class="px-3 py-2 text-gray-200">{{ Str::limit($d->entity_key, 32) }}</td>
                            <td class="px-3 py-2"><span class="px-1.5 py-0.5 rounded bg-gray-700 text-gray-400 text-xs">{{ $d->entity_type }}</span></td>
                            <td class="px-3 py-2"><code class="text-gray-400">{{ $d->dimension }}</code></td>
                            <td class="px-3 py-2 text-right text-gray-300">{{ number_format($d->baseline_mean ?? 0, 3) }}</td>
                            <td class="px-3 py-2 text-right font-semibold {{ ($d->baseline_stddev ?? 0) > 5 ? 'text-red-400' : (($d->baseline_stddev ?? 0) > 2 ? 'text-yellow-400' : 'text-gray-300') }}">
                                {{ number_format($d->baseline_stddev ?? 0, 3) }}
                            </td>
                            <td class="px-3 py-2 text-center text-gray-500">{{ $d->sample_count }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $d->computed_at ? \Carbon\Carbon::parse($d->computed_at)->diffForHumans() : '—' }}</td>
                            <td class="px-3 py-2">
                                <a href="{{ route('ueba.entity-history', ['entity_key' => $d->entity_key, 'entity_type' => $d->entity_type]) }}" class="text-xs text-cyan-400 hover:underline">History</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>

        <p class="text-xs text-gray-600 italic">High standard deviation indicates behavioral variability — not necessarily malicious. Use as an investigation starting point. Behavioral analytics are advisory-only and explainable. No automatic enforcement is executed.</p>
    </div>
</x-app-layout>
