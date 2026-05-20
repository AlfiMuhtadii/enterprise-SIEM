<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Multi-Hop Pivot Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1">Bounded graph traversal across entity relationships. Advisory-only.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Advisory:</strong> Multi-hop pivot is read-only graph traversal. Maximum hop depth is enforced. No enforcement actions are triggered.
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-3">Pivot Configuration</h3>
            <div class="grid grid-cols-3 gap-4 text-xs">
                <div>
                    <label class="text-gray-400">Starting Entity ID</label>
                    <input type="text" class="mt-1 block w-full rounded bg-gray-800 border border-gray-700 text-gray-200 text-xs px-2 py-1.5" placeholder="entity_key or entity_id" readonly>
                </div>
                <div>
                    <label class="text-gray-400">Max Hop Depth</label>
                    <select class="mt-1 block w-full rounded bg-gray-800 border border-gray-700 text-gray-200 text-xs px-2 py-1.5" readonly>
                        <option>1</option>
                        <option selected>2</option>
                        <option>3</option>
                    </select>
                </div>
                <div>
                    <label class="text-gray-400">Source Domains</label>
                    <div class="mt-1 max-h-24 overflow-y-auto space-y-1">
                        @foreach($domains as $domain)
                        <label class="flex items-center gap-1 text-xs text-gray-300">
                            <input type="checkbox" checked class="rounded" readonly>
                            {{ $domain }}
                        </label>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button class="px-3 py-1.5 text-xs rounded bg-blue-900/40 text-blue-300 border border-blue-700/40 opacity-60 cursor-not-allowed">
                    Run Pivot (Advisory — read-only, operator-initiated)
                </button>
            </div>
        </div>

        <div class="rounded border border-gray-700/50 bg-gray-900/40 p-4">
            <h3 class="text-sm font-semibold text-gray-200 mb-2">Pivot Results</h3>
            <p class="text-xs text-gray-500">Enter an entity key and run pivot to see multi-hop graph results here.</p>
        </div>

    </div>
</x-app-layout>
