@if ($rows->isEmpty())
    <div class="px-5 py-6 text-xs text-center text-cyan-200/30">No scored entities yet.</div>
@else
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                <th class="px-5 py-2.5">Key</th>
                <th class="px-4 py-2.5 text-center">Score</th>
                <th class="px-4 py-2.5">Level</th>
                <th class="px-4 py-2.5"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-cyan-100/5">
            @foreach ($rows as $row)
                @php
                    $lvlColor = match($row->risk_level) {
                        'critical' => 'text-red-300 border-red-400/40 bg-red-500/10',
                        'high'     => 'text-orange-300 border-orange-400/40 bg-orange-500/10',
                        'medium'   => 'text-yellow-300 border-yellow-400/40 bg-yellow-500/10',
                        'low'      => 'text-green-300 border-green-400/40 bg-green-500/10',
                        default    => 'text-cyan-200/40 border-cyan-200/20 bg-black/20',
                    };
                @endphp
                <tr class="hover:bg-cyan-100/3 transition">
                    <td class="px-5 py-2.5">
                        <span class="mono-ui text-xs text-cyan-200/75 break-all">{{ Str::limit($row->entity_key, 36) }}</span>
                    </td>
                    <td class="px-4 py-2.5 text-center">
                        <span class="mono-ui text-xs font-bold text-cyan-50">{{ number_format($row->risk_score, 1) }}</span>
                    </td>
                    <td class="px-4 py-2.5">
                        <span class="rounded border px-1.5 py-0.5 text-xs {{ $lvlColor }}">
                            {{ strtoupper($row->risk_level ?? '—') }}
                        </span>
                    </td>
                    <td class="px-4 py-2.5">
                        <a href="{{ route('entity.risk-breakdown', $row->id) }}"
                           class="text-xs text-cyan-400 hover:text-cyan-200 transition">Breakdown →</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
@endif
