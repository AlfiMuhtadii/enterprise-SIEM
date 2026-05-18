<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">
            Resilience Run History
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="glass-card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-cyan-100">All Runs</h3>
                    <a href="{{ route('resilience.index') }}" class="text-sm text-cyan-400 hover:text-cyan-200">← Dashboard</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-cyan-200">
                        <thead>
                            <tr class="border-b border-cyan-100/20 text-cyan-400 text-xs uppercase">
                                <th class="pb-2 text-left">Run ID</th>
                                <th class="pb-2 text-left">Scenario</th>
                                <th class="pb-2 text-center">Type</th>
                                <th class="pb-2 text-center">Status</th>
                                <th class="pb-2 text-center">Shadow</th>
                                <th class="pb-2 text-center">Replay</th>
                                <th class="pb-2 text-center">No Dups</th>
                                <th class="pb-2 text-right">Duration</th>
                                <th class="pb-2 text-right">Started</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/10">
                            @forelse ($runs as $run)
                            <tr class="hover:bg-cyan-900/20">
                                <td class="py-2">
                                    <a href="{{ route('resilience.show', $run->run_id) }}" class="font-mono text-xs text-cyan-400 hover:text-cyan-200">
                                        {{ $run->run_id }}
                                    </a>
                                </td>
                                <td class="py-2 text-xs">{{ $run->scenario_name }}</td>
                                <td class="py-2 text-center">
                                    <span class="px-1.5 py-0.5 rounded text-xs font-mono
                                        @if($run->scenario_type === 'active') bg-blue-900/40 text-blue-300
                                        @else bg-cyan-900/40 text-cyan-300 @endif">
                                        {{ $run->scenario_type }}
                                    </span>
                                </td>
                                <td class="py-2 text-center">
                                    <span class="px-2 py-0.5 rounded text-xs font-mono
                                        @if($run->status === 'passed') bg-green-900/40 text-green-300
                                        @elseif($run->status === 'failed') bg-red-900/40 text-red-300
                                        @else bg-yellow-900/40 text-yellow-300 @endif">
                                        {{ $run->status }}
                                    </span>
                                </td>
                                <td class="py-2 text-center text-xs {{ $run->endpoint_shadow_isolated ? 'text-green-400' : 'text-red-400' }}">
                                    {{ $run->endpoint_shadow_isolated ? 'Yes' : 'No' }}
                                </td>
                                <td class="py-2 text-center text-xs {{ $run->replay_safe ? 'text-green-400' : 'text-red-400' }}">
                                    {{ $run->replay_safe ? 'Yes' : 'No' }}
                                </td>
                                <td class="py-2 text-center text-xs {{ $run->no_duplicates ? 'text-green-400' : 'text-red-400' }}">
                                    {{ $run->no_duplicates ? 'Yes' : 'No' }}
                                </td>
                                <td class="py-2 text-right font-mono text-xs text-cyan-400/70">
                                    {{ $run->durationSeconds() !== null ? round($run->durationSeconds(), 2) . 's' : '—' }}
                                </td>
                                <td class="py-2 text-right text-xs text-cyan-400/70 whitespace-nowrap">
                                    {{ $run->started_at?->format('Y-m-d H:i:s') }}
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="9" class="py-6 text-center text-cyan-400/50 text-sm">No resilience runs yet.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    {{ $runs->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
