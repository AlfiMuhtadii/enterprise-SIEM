<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            FP/FN Analysis Viewer
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Code-level maturity workflows are synthetic, replay-safe, advisory-only, and bounded.
            No destructive execution, autonomous remediation, or real exploit activity is executed.
        </div>

        <div class="bg-gray-50 border border-gray-200 rounded p-3 text-sm text-gray-700">
            No automatic suppression. No automatic closure. All findings are advisory recommendations only.
        </div>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Rule</th>
                        <th class="px-4 py-2 text-left">Noisy</th>
                        <th class="px-4 py-2 text-left">Benign</th>
                        <th class="px-4 py-2 text-left">Suppressed Recur</th>
                        <th class="px-4 py-2 text-left">Missed Chain</th>
                        <th class="px-4 py-2 text-left">Weak Evid</th>
                        <th class="px-4 py-2 text-left">FP Rate</th>
                        <th class="px-4 py-2 text-left">FN Rate</th>
                        <th class="px-4 py-2 text-left">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($reports as $r)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ $r->analyzed_rule_id ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $r->noisy_rule_count }}</td>
                            <td class="px-4 py-2">{{ $r->repeated_benign_count }}</td>
                            <td class="px-4 py-2">{{ $r->suppressed_recurring_count }}</td>
                            <td class="px-4 py-2">{{ $r->missed_chain_count }}</td>
                            <td class="px-4 py-2">{{ $r->weak_evidence_count }}</td>
                            <td class="px-4 py-2">{{ number_format($r->fp_prevalence_rate * 100, 1) }}%</td>
                            <td class="px-4 py-2">{{ number_format($r->fn_prevalence_rate * 100, 1) }}%</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-4 text-center text-gray-400 text-sm">No FP/FN reports yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $reports->links() }}</div>

    </div>
</x-app-layout>
