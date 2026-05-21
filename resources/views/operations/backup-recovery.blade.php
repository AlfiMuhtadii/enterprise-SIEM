<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Backup &amp; Recovery Validation</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Operational hardening and recovery tooling only. No autonomous infrastructure mutation.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-6xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Validation is read-only and non-destructive. No live destructive restore automation.
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach(['total', 'verified', 'corrupt', 'pending'] as $key)
            <div class="glass-card p-4 text-center">
                <div class="text-xs text-gray-400 uppercase">{{ $key }}</div>
                <div class="text-2xl font-bold {{ $key === 'corrupt' ? 'text-red-400' : ($key === 'verified' ? 'text-green-400' : 'text-cyan-300') }}">
                    {{ $summary[$key] }}
                </div>
            </div>
            @endforeach
        </div>

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Registered Backups</h3>
            @if($backups->isEmpty())
                <p class="text-xs text-gray-500">No backups registered.</p>
            @else
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-left text-gray-300">
                <thead class="text-gray-400 uppercase border-b border-gray-700">
                    <tr><th class="py-2 pr-3">Backup ID</th><th class="py-2 pr-3">Type</th><th class="py-2 pr-3">Scope</th><th class="py-2 pr-3">Status</th><th class="py-2">Backed At</th></tr>
                </thead>
                <tbody>
                    @foreach($backups as $b)
                    <tr class="border-b border-gray-800">
                        <td class="py-1.5 pr-3 font-mono text-cyan-400 text-xs">{{ $b->backup_id }}</td>
                        <td class="py-1.5 pr-3">{{ $b->backup_type }}</td>
                        <td class="py-1.5 pr-3">{{ $b->scope }}</td>
                        <td class="py-1.5 pr-3">
                            <span class="px-1.5 py-0.5 rounded text-xs
                                {{ $b->status === 'verified' ? 'bg-green-900/40 text-green-300' : ($b->status === 'corrupted' ? 'bg-red-900/40 text-red-300' : 'bg-gray-700 text-gray-400') }}">
                                {{ $b->status }}
                            </span>
                        </td>
                        <td class="py-1.5 text-gray-500">{{ $b->backup_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @endif
        </div>

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Recent Validations</h3>
            @forelse($validations as $v)
            <div class="flex gap-4 items-center py-1 border-b border-gray-800 text-xs">
                <span class="font-mono text-cyan-400">{{ $v->validation_id }}</span>
                <span>{{ $v->validation_type }}</span>
                <span class="{{ $v->result === 'pass' ? 'text-green-400' : ($v->result === 'fail' ? 'text-red-400' : 'text-amber-400') }}">{{ $v->result }}</span>
                <span class="ml-auto text-gray-500">{{ \Carbon\Carbon::parse($v->created_at)->format('H:i:s') }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No validations yet.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
