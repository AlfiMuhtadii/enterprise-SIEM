<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">Investigations</p>
                <h2 class="mt-2 text-xl font-semibold leading-tight text-main-ui">My Work Queue</h2>
                <p class="mt-1 text-xs text-cyan-200/50">Assigned to: {{ auth()->user()->name }}</p>
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
                <p class="text-sm text-cyan-200/40">No active investigations assigned to you.</p>
            </div>
        @else
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        {{ $investigations->count() }} assigned investigation{{ $investigations->count() === 1 ? '' : 's' }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-3">ID</th>
                            <th class="px-4 py-3">Title</th>
                            <th class="px-4 py-3">State</th>
                            <th class="px-4 py-3">Severity</th>
                            <th class="px-4 py-3">P</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($investigations as $inv)
                            @php
                                $stateColor = match($inv->state) {
                                    'escalated'     => 'text-red-300 border-red-400/40 bg-red-500/10',
                                    'investigating' => 'text-purple-300 border-purple-400/40 bg-purple-500/10',
                                    'triaged'       => 'text-blue-300 border-blue-400/40 bg-blue-500/10',
                                    default         => 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10',
                                };
                                $sevColor = match($inv->severity) {
                                    'critical' => 'text-red-300',
                                    'high'     => 'text-orange-300',
                                    'medium'   => 'text-yellow-300',
                                    default    => 'text-cyan-200/50',
                                };
                            @endphp
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-3"><span class="mono-ui text-xs text-cyan-200/60">{{ $inv->investigation_id }}</span></td>
                                <td class="px-4 py-3 max-w-xs"><span class="text-sm text-cyan-50">{{ Str::limit($inv->title, 48) }}</span></td>
                                <td class="px-4 py-3">
                                    <span class="rounded border px-1.5 py-0.5 text-xs {{ $stateColor }}">{{ str_replace('_',' ',$inv->state) }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs font-semibold {{ $sevColor }}">{{ strtoupper($inv->severity) }}</td>
                                <td class="px-4 py-3 text-xs text-cyan-200/50 mono-ui">P{{ $inv->priority }}</td>
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
