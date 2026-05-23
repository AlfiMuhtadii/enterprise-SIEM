<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Bounded Automation Viewer</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm text-yellow-800">
            advisory-only — Automation checks are advisory-only. No autonomous action is ever taken.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left">Type</th>
                    <th class="px-4 py-2 text-left">Scope</th>
                    <th class="px-4 py-2 text-left">Check Passed</th>
                    <th class="px-4 py-2 text-left">Autonomous</th>
                    <th class="px-4 py-2 text-left">Time</th>
                </tr></thead>
                <tbody>
                @forelse($reports as $r)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $r->automation_type }}</td>
                        <td class="px-4 py-2">{{ $r->service_scope }}</td>
                        <td class="px-4 py-2 {{ $r->check_passed ? 'text-green-600' : 'text-red-600' }}">{{ $r->check_passed ? 'Pass' : 'Fail' }}</td>
                        <td class="px-4 py-2 text-green-700 font-semibold">Never</td>
                        <td class="px-4 py-2 text-gray-500">{{ $r->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-center text-gray-400">No records</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $reports->links() }}</div>
    </div>
</x-app-layout>
