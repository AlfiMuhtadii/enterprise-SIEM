<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Tamper Visibility Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Endpoint operations are advisory-only. No autonomous containment or enforcement is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-red-400/30 bg-red-900/10 px-4 py-3 text-sm text-red-300">
            <strong>Advisory Notice:</strong> Tamper indicators are explainable, evidence-linked findings. No automated response, no host isolation, no process termination. Analyst review required.
        </div>
        <div class="flex justify-end">
            <a href="{{ route('endpoint-fleet.dashboard') }}" class="text-xs text-cyan-400 hover:underline">← Fleet Dashboard</a>
        </div>

        <div class="glass-card p-4">
            <form method="GET" action="{{ route('endpoint-fleet.tamper') }}" class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Tamper Type</label>
                    <select name="tamper_type" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2">
                        <option value="">All</option>
                        @foreach($tamperTypes as $t)
                            <option value="{{ $t }}" @selected($t === $tamperType)>{{ str_replace('_', ' ', $t) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Severity</label>
                    <select name="severity" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2">
                        <option value="">All</option>
                        @foreach(['critical', 'high', 'medium', 'low'] as $s)
                            <option value="{{ $s }}" @selected($s === $severity)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Days</label>
                    <input type="number" name="days" class="bg-gray-800 border border-gray-600 text-gray-200 text-xs rounded px-3 py-2 w-20" value="{{ $days }}" min="1" max="30">
                </div>
                <button type="submit" class="px-4 py-2 text-xs rounded bg-red-900/50 text-red-300 border border-red-700/40 hover:bg-red-900/70">Filter</button>
            </form>
        </div>

        {{-- Per-agent tamper summary --}}
        @if($summary->isNotEmpty())
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Agents with Tamper Events</h3>
            <div class="flex flex-wrap gap-2">
                @foreach($summary as $row)
                    <div class="border border-red-700/30 rounded px-3 py-2 text-xs bg-red-900/10">
                        <div class="text-gray-200 font-semibold">{{ $row->hostname }}</div>
                        <div class="text-red-300">{{ $row->tamper_count }} events</div>
                        <div class="text-gray-500">{{ $row->tamper_types }}</div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        <div class="glass-card p-0 overflow-hidden">
            <div class="flex justify-between items-center px-4 py-3 border-b border-gray-700">
                <span class="text-sm font-semibold text-cyan-200">Tamper Events</span>
                <span class="text-xs text-gray-500">{{ $events->count() }} results — all advisory-only</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-800/60 text-gray-400 uppercase tracking-wider">
                        <tr>
                            <th class="px-3 py-2 text-left">Agent</th>
                            <th class="px-3 py-2 text-left">Type</th>
                            <th class="px-3 py-2 text-center">Severity</th>
                            <th class="px-3 py-2 text-center">Confidence</th>
                            <th class="px-3 py-2 text-left">Description</th>
                            <th class="px-3 py-2 text-left">Detected</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                    @forelse($events as $e)
                        <tr class="hover:bg-gray-800/30">
                            <td class="px-3 py-2 text-cyan-400">{{ $e->agent?->hostname ?? 'unknown' }}</td>
                            <td class="px-3 py-2 text-gray-300">{{ str_replace('_', ' ', $e->tamper_type) }}</td>
                            <td class="px-3 py-2 text-center">
                                <span class="px-1.5 py-0.5 rounded {{ $e->severity === 'critical' ? 'bg-red-900/40 text-red-300' : ($e->severity === 'high' ? 'bg-orange-900/40 text-orange-300' : ($e->severity === 'medium' ? 'bg-yellow-900/40 text-yellow-300' : 'bg-gray-700 text-gray-400')) }}">{{ $e->severity }}</span>
                            </td>
                            <td class="px-3 py-2 text-center text-gray-300">{{ round(($e->confidence ?? 0) * 100) }}%</td>
                            <td class="px-3 py-2 text-gray-400">{{ Str::limit($e->description, 60) }}</td>
                            <td class="px-3 py-2 text-gray-500">{{ \Carbon\Carbon::parse($e->detected_at)->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">No tamper events found.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-xs text-gray-600 italic">All tamper indicators are advisory-only. No automated enforcement is executed. Evidence is always visible and explainable.</p>
    </div>
</x-app-layout>
