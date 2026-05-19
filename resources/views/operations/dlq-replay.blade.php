<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">DLQ Replay Center</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Operational hardening and recovery tooling only. No autonomous infrastructure mutation.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-6xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            All replay jobs start in dry-run mode. Simulation required before execution.
            Bounded batch limits enforced. Duplicate prevention active.
        </div>

        @if(session('success'))
        <div class="rounded border border-green-400/30 bg-green-900/10 px-4 py-2 text-sm text-green-300">{{ session('success') }}</div>
        @endif

        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Create Replay Job</h3>
            <form method="POST" action="{{ route('operations.dlq.create') }}" class="flex gap-3 items-end flex-wrap">
                @csrf
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Topic</label>
                    <input name="topic" type="text" placeholder="xdr.alerts.dlq"
                           class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-48">
                </div>
                <div>
                    <label class="text-xs text-gray-400 block mb-1">From Offset</label>
                    <input name="from_offset" type="number" value="0" min="0"
                           class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-32">
                </div>
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Batch Limit (max 500)</label>
                    <input name="batch_limit" type="number" value="100" min="1" max="500"
                           class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-32">
                </div>
                <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">
                    Create (Dry-Run)
                </button>
            </form>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Replay Jobs</h3>
            @if($jobs->isEmpty())
                <p class="text-xs text-gray-500">No replay jobs.</p>
            @else
            <table class="w-full text-xs text-left text-gray-300">
                <thead class="text-gray-400 uppercase border-b border-gray-700">
                    <tr><th class="py-2 pr-3">Job ID</th><th class="py-2 pr-3">Topic</th><th class="py-2 pr-3">Limit</th><th class="py-2 pr-3">Risk</th><th class="py-2 pr-3">Status</th><th class="py-2">Actions</th></tr>
                </thead>
                <tbody>
                    @foreach($jobs as $j)
                    <tr class="border-b border-gray-800">
                        <td class="py-1.5 pr-3 font-mono text-cyan-400 text-xs">{{ $j->job_id }}</td>
                        <td class="py-1.5 pr-3">{{ $j->topic }}</td>
                        <td class="py-1.5 pr-3">{{ $j->batch_limit }}</td>
                        <td class="py-1.5 pr-3">{{ number_format($j->risk_score * 100) }}%</td>
                        <td class="py-1.5 pr-3">
                            <span class="px-1.5 py-0.5 rounded text-xs {{ $j->dry_run ? 'bg-blue-900/40 text-blue-300' : 'bg-amber-900/40 text-amber-300' }}">
                                {{ $j->dry_run ? 'dry-run' : 'LIVE' }} / {{ $j->status }}
                            </span>
                        </td>
                        <td class="py-1.5 flex gap-2">
                            @if(!$j->isTerminal())
                            <form method="POST" action="{{ route('operations.dlq.simulate', $j->job_id) }}">
                                @csrf
                                <button type="submit" class="text-xs text-cyan-400 hover:underline">Simulate</button>
                            </form>
                            <form method="POST" action="{{ route('operations.dlq.cancel', $j->job_id) }}">
                                @csrf
                                <button type="submit" class="text-xs text-red-400 hover:underline">Cancel</button>
                            </form>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>
</x-app-layout>
