<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Rule Evidence & Replay Fixture Governance
            <span class="ml-2 text-sm font-normal text-yellow-600">ADVISORY ONLY — plan_approved: false</span>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- Summary cards --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded shadow p-4 text-center">
                    <div class="text-3xl font-bold text-gray-800">{{ $summary['total'] ?? 0 }}</div>
                    <div class="text-sm text-gray-500">Total Rules</div>
                </div>
                <div class="bg-red-50 rounded shadow p-4 text-center">
                    <div class="text-3xl font-bold text-red-700">{{ $summary['missing_fixture'] ?? 0 }}</div>
                    <div class="text-sm text-gray-500">Missing Fixture</div>
                </div>
                <div class="bg-yellow-50 rounded shadow p-4 text-center">
                    <div class="text-3xl font-bold text-yellow-700">{{ $summary['tier_1_count'] ?? 0 }}</div>
                    <div class="text-sm text-gray-500">Tier 1 — Immediate</div>
                </div>
                <div class="bg-green-50 rounded shadow p-4 text-center">
                    <div class="text-3xl font-bold text-green-700">{{ $summary['has_fixture'] ?? 0 }}</div>
                    <div class="text-sm text-gray-500">Have Fixture</div>
                </div>
            </div>

            {{-- Tier legend --}}
            <div class="bg-white rounded shadow p-4 mb-6 text-sm">
                <p class="font-semibold mb-2">Priority Tiers</p>
                <ul class="space-y-1">
                    <li><span class="font-mono text-red-700">tier_1_immediate</span> — staged_active rules missing replay fixture ({{ $summary['tier_1_count'] ?? 0 }} rules)</li>
                    <li><span class="font-mono text-yellow-700">tier_2_next_batch</span> — shadow rules in soaked domains or confidence ≥ 0.72 ({{ $summary['tier_2_count'] ?? 0 }} rules)</li>
                    <li><span class="font-mono text-gray-500">tier_3_deferred</span> — all others ({{ $summary['tier_3_count'] ?? 0 }} rules)</li>
                </ul>
            </div>

            @if($backlog->isEmpty())
                <div class="bg-yellow-50 border border-yellow-200 rounded p-6 text-center">
                    <p class="text-yellow-700 font-semibold">No inventory data.</p>
                    <p class="text-sm text-gray-500 mt-1">Run <code>php artisan rule:evidence-inventory</code> to populate the backlog.</p>
                </div>
            @else
                {{-- Backlog table --}}
                <div class="bg-white rounded shadow overflow-hidden">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Rule ID</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Domain</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Confidence</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Conf. Source</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Fixture</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Evidence</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Priority</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($backlog as $row)
                            @php
                                $rowClass = match($row->priority_tier) {
                                    'tier_1_immediate'  => 'bg-red-50',
                                    'tier_2_next_batch' => 'bg-yellow-50',
                                    default             => '',
                                };
                            @endphp
                            <tr class="{{ $rowClass }}">
                                <td class="px-4 py-2 font-mono text-xs">{{ $row->rule_id }}</td>
                                <td class="px-4 py-2">{{ $row->domain }}</td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-0.5 rounded text-xs {{ $row->status === 'staged_active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                        {{ $row->status }}
                                    </span>
                                </td>
                                <td class="px-4 py-2">{{ number_format($row->confidence, 2) }}</td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-0.5 rounded text-xs {{ $row->confidence_source === 'empirical' ? 'bg-green-100 text-green-800' : ($row->confidence_source === 'fixture_tested' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-500') }}">
                                        {{ $row->confidence_source }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-center">
                                    {!! $row->has_replay_fixture ? '<span class="text-green-600">✓</span>' : '<span class="text-red-500">✗</span>' !!}
                                </td>
                                <td class="px-4 py-2 text-center">
                                    {!! $row->has_validation_evidence ? '<span class="text-green-600">✓</span>' : '<span class="text-red-500">✗</span>' !!}
                                </td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $row->priority_tier }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
