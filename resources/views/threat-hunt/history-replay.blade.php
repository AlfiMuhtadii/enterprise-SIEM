<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Historical Hunt Replay</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Threat hunting is advisory-only and non-destructive.</p>
            </div>
            <a href="{{ route('threat-hunt.dashboard') }}" class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Dashboard</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Hunt History ({{ count($hunts) }})</h3>
                @if ($hunts->isEmpty())
                    <p class="text-cyan-400/50 text-sm">No hunt history yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-cyan-200/80">
                            <thead>
                                <tr class="text-left text-xs text-cyan-400/60 border-b border-cyan-200/10">
                                    <th class="pb-2 pr-4">Hunt ID</th>
                                    <th class="pb-2 pr-4">Title</th>
                                    <th class="pb-2 pr-4">Domain</th>
                                    <th class="pb-2 pr-4">Status</th>
                                    <th class="pb-2 pr-4">Results</th>
                                    <th class="pb-2 pr-4">Scope</th>
                                    <th class="pb-2 pr-4">Executed</th>
                                    <th class="pb-2">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($hunts as $hunt)
                                <tr class="border-b border-cyan-200/5 hover:bg-cyan-100/5">
                                    <td class="py-2 pr-4 font-mono text-xs">
                                        <a href="{{ route('threat-hunt.show', $hunt->hunt_id) }}" class="text-cyan-400 hover:text-cyan-200">{{ $hunt->hunt_id }}</a>
                                    </td>
                                    <td class="py-2 pr-4 text-xs">{{ Str::limit($hunt->title, 40) }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs text-cyan-400/70">{{ $hunt->queries->first()?->query_domain ?? '—' }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="px-1.5 py-0.5 rounded text-xs {{ $hunt->status === 'completed' ? 'bg-green-900/40 text-green-300' : 'bg-cyan-900/40 text-cyan-400' }}">{{ $hunt->status }}</span>
                                    </td>
                                    <td class="py-2 pr-4 text-xs">{{ $hunt->result_count }}</td>
                                    <td class="py-2 pr-4 text-xs text-cyan-400/60">{{ $hunt->replay_scope }}</td>
                                    <td class="py-2 pr-4 text-xs text-cyan-400/60">{{ $hunt->executed_at?->diffForHumans() }}</td>
                                    <td class="py-2">
                                        <form method="POST" action="{{ route('threat-hunt.replay', $hunt->hunt_id) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs text-cyan-400 hover:text-cyan-200">Replay</button>
                                        </form>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">
                    Replay creates a new hunt record with the same query parameters. Original hunt records are never modified (append-only).
                    Replay results may differ from the original if underlying telemetry has changed.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
