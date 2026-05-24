<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Detection Quality Scorecard
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Code-level maturity workflows are synthetic, replay-safe, advisory-only, and bounded.
            No destructive execution, autonomous remediation, or real exploit activity is executed.
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Rule</th>
                        <th class="px-4 py-2 text-left">Precision</th>
                        <th class="px-4 py-2 text-left">Recall</th>
                        <th class="px-4 py-2 text-left">FP Rate</th>
                        <th class="px-4 py-2 text-left">FN Rate</th>
                        <th class="px-4 py-2 text-left">Coverage</th>
                        <th class="px-4 py-2 text-left">ATT&CK</th>
                        <th class="px-4 py-2 text-left">Overall</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($scorecards as $s)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ $s->scored_rule_id ?? '—' }}</td>
                            <td class="px-4 py-2">{{ number_format($s->precision_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($s->recall_score * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($s->false_positive_rate * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($s->false_negative_rate * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($s->detection_coverage * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($s->attack_coverage * 100, 1) }}%</td>
                            <td class="px-4 py-2 font-semibold {{ $s->overall_quality_score >= 0.75 ? 'text-green-600' : 'text-yellow-600' }}">
                                {{ number_format($s->overall_quality_score * 100, 1) }}%
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $s->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-4 text-center text-gray-400 text-sm">No scorecards yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $scorecards->links() }}</div>

    </div>
</x-app-layout>
