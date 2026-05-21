<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Retention Policy Center</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Operational hardening and recovery tooling only. No autonomous infrastructure mutation.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-6xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            All retention executions are dry-run by default. Actual deletion requires policy status=active and explicit action.
            Append-only audit tables are never eligible for retention cleanup.
        </div>

        @if(session('success'))
        <div class="rounded border border-green-400/30 bg-green-900/10 px-4 py-2 text-sm text-green-300">{{ session('success') }}</div>
        @endif

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Retention Policies</h3>
            @if($policies->isEmpty())
                <p class="text-xs text-gray-500">No retention policies configured.</p>
            @else
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-left text-gray-300">
                <thead class="text-gray-400 uppercase border-b border-gray-700">
                    <tr><th class="py-2 pr-3">Domain</th><th class="py-2 pr-3">Retention</th><th class="py-2 pr-3">Batch</th><th class="py-2 pr-3">Status</th><th class="py-2">Actions</th></tr>
                </thead>
                <tbody>
                    @foreach($policies as $p)
                    <tr class="border-b border-gray-800">
                        <td class="py-1.5 pr-3 font-mono">{{ $p->domain }}</td>
                        <td class="py-1.5 pr-3">{{ $p->retention_days }}d</td>
                        <td class="py-1.5 pr-3">{{ $p->batch_size }}</td>
                        <td class="py-1.5 pr-3">
                            <span class="px-1.5 py-0.5 rounded text-xs
                                {{ $p->status === 'active' ? 'bg-green-900/40 text-green-300' : 'bg-gray-700 text-gray-400' }}">
                                {{ $p->status }}
                            </span>
                        </td>
                        <td class="py-1.5 flex gap-2">
                            <form method="POST" action="{{ route('operations.retention.preview', $p->policy_id) }}">
                                @csrf
                                <button type="submit" class="text-xs text-cyan-400 hover:underline">Preview</button>
                            </form>
                            <form method="POST" action="{{ route('operations.retention.dry-run', $p->policy_id) }}">
                                @csrf
                                <button type="submit" class="text-xs text-amber-400 hover:underline">Dry-Run</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @endif
        </div>

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Recent Executions</h3>
            @forelse($executions as $e)
            <div class="flex gap-4 items-center py-1 border-b border-gray-800 text-xs">
                <span class="font-mono text-cyan-400">{{ $e->execution_id }}</span>
                <span class="text-gray-400">{{ $e->policy?->domain }}</span>
                <span class="{{ $e->dry_run ? 'text-blue-400' : 'text-amber-400' }}">{{ $e->dry_run ? 'dry-run' : 'LIVE' }}</span>
                <span>scanned: {{ $e->rows_scanned }} / eligible: {{ $e->rows_eligible }} / deleted: {{ $e->rows_deleted }}</span>
                <span class="ml-auto text-gray-500">{{ $e->created_at?->format('H:i:s') }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No executions yet.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
