<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">Investigations</p>
                <h2 class="mt-2 text-xl font-semibold leading-tight text-main-ui">Escalation View</h2>
            </div>
            <a href="{{ route('investigation.index') }}"
               class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                ← Dashboard
            </a>
        </div>
    </x-slot>

    <div class="space-y-4">

        @if ($investigations->isEmpty())
            <div class="glass-card p-10 text-center">
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full border border-green-400/30 bg-green-500/8">
                    <svg class="h-6 w-6 text-green-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <p class="text-sm text-cyan-200/40">No escalated investigations at this time.</p>
            </div>
        @else
            <div class="rounded-lg border border-red-400/20 bg-red-500/5 px-4 py-3 text-xs text-red-200/70">
                {{ $investigations->count() }} escalated investigation{{ $investigations->count() === 1 ? '' : 's' }} require attention.
            </div>
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <span class="text-xs uppercase tracking-[0.12em] text-red-300/60">
                        Escalated — {{ $investigations->count() }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-3">ID</th>
                            <th class="px-4 py-3">Title</th>
                            <th class="px-4 py-3">Severity</th>
                            <th class="px-4 py-3">P</th>
                            <th class="px-4 py-3">Assignee</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($investigations as $inv)
                            @php
                                $sevColor = match($inv->severity) {
                                    'critical' => 'text-red-300',
                                    'high'     => 'text-orange-300',
                                    'medium'   => 'text-yellow-300',
                                    default    => 'text-cyan-200/50',
                                };
                            @endphp
                            <tr class="hover:bg-red-500/5 transition">
                                <td class="px-5 py-3"><span class="mono-ui text-xs text-cyan-200/60">{{ $inv->investigation_id }}</span></td>
                                <td class="px-4 py-3 max-w-xs"><span class="text-sm text-cyan-50">{{ Str::limit($inv->title, 48) }}</span></td>
                                <td class="px-4 py-3 text-xs font-bold {{ $sevColor }}">{{ strtoupper($inv->severity) }}</td>
                                <td class="px-4 py-3 text-xs text-cyan-200/50 mono-ui">P{{ $inv->priority }}</td>
                                <td class="px-4 py-3 text-xs text-cyan-200/60">{{ $inv->assignee_name ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs text-cyan-200/45">{{ \Carbon\Carbon::parse($inv->created_at)->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('investigation.show', $inv->id) }}" class="text-xs text-cyan-400 hover:text-cyan-200 transition">Open →</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif

    </div>
</x-app-layout>
