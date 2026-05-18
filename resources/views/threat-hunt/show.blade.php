<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">{{ $hunt->hunt_id }}</h2>
                <p class="text-xs text-cyan-400/50 mt-0.5">{{ $hunt->title }}</p>
            </div>
            <div class="flex gap-2">
                <form method="POST" action="{{ route('threat-hunt.replay', $hunt->hunt_id) }}">
                    @csrf
                    <button type="submit" class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">Replay Hunt</button>
                </form>
                <a href="{{ route('threat-hunt.dashboard') }}" class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Dashboard</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('success'))
                <div class="glass-card p-4 border border-green-400/30 text-green-300 text-sm">{{ session('success') }}</div>
            @endif

            {{-- Hunt metadata --}}
            <div class="glass-card p-6">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                    <div><div class="text-xs text-cyan-400/60 mb-1">Status</div>
                        <span class="px-2 py-0.5 rounded text-xs {{ $hunt->status === 'completed' ? 'bg-green-900/40 text-green-300' : 'bg-cyan-900/40 text-cyan-400' }}">{{ $hunt->status }}</span>
                    </div>
                    <div><div class="text-xs text-cyan-400/60 mb-1">Results</div><div class="text-cyan-100 font-bold">{{ $hunt->result_count }}</div></div>
                    <div><div class="text-xs text-cyan-400/60 mb-1">Scope</div><div class="text-cyan-100">{{ $hunt->replay_scope }}</div></div>
                    <div><div class="text-xs text-cyan-400/60 mb-1">Executed</div><div class="text-xs text-cyan-400/60">{{ $hunt->executed_at?->toIso8601String() }}</div></div>
                    @if ($hunt->queries->first())
                    <div><div class="text-xs text-cyan-400/60 mb-1">Domain</div><div class="font-mono text-cyan-100 text-xs">{{ $hunt->queries->first()->query_domain }}</div></div>
                    @endif
                    <div><div class="text-xs text-cyan-400/60 mb-1">Trace ID</div><div class="font-mono text-cyan-400/50 text-xs truncate">{{ $hunt->trace_id }}</div></div>
                </div>
                @if ($hunt->queries->first()?->query_filters)
                <div class="mt-4">
                    <div class="text-xs text-cyan-400/60 mb-1">Filters Applied</div>
                    <pre class="text-xs bg-black/30 rounded p-2 text-cyan-300/80">{{ json_encode($hunt->queries->first()->query_filters, JSON_PRETTY_PRINT) }}</pre>
                </div>
                @endif
            </div>

            {{-- Results --}}
            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Results ({{ $hunt->results->count() }})</h3>
                @if ($hunt->results->isEmpty())
                    <p class="text-cyan-400/50 text-sm">No results matched the hunt query.</p>
                @else
                    <div class="space-y-2 max-h-[500px] overflow-y-auto">
                        @foreach ($hunt->results as $result)
                        <div class="border border-cyan-200/10 rounded p-3 text-xs">
                            <span class="px-1.5 py-0.5 rounded bg-cyan-900/40 text-cyan-300 text-xs mr-2">{{ $result->result_type }}</span>
                            <pre class="mt-2 text-cyan-300/70 overflow-x-auto">{{ json_encode($result->result_data, JSON_PRETTY_PRINT) }}</pre>
                        </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">Threat hunting is advisory-only and non-destructive. All results are read-only snapshots.</p>
            </div>
        </div>
    </div>
</x-app-layout>
