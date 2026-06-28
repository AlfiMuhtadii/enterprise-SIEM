<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">RAG Knowledge Base Seeding</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4">
        @if(session('status'))
            <div class="mb-4 p-3 bg-green-100 text-green-800 rounded">{{ session('status') }}</div>
        @endif

        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="bg-white shadow rounded p-4 text-center">
                <div class="text-2xl font-bold text-blue-600">{{ $seededCount }}</div>
                <div class="text-sm text-gray-500">KB entries (seeded)</div>
            </div>
            <div class="bg-white shadow rounded p-4 text-center">
                <div class="text-2xl font-bold">{{ $fixtures->count() }}</div>
                <div class="text-sm text-gray-500">Active fixtures</div>
            </div>
            <div class="bg-white shadow rounded p-4 text-center">
                <div class="text-2xl font-bold">{{ $history->count() }}</div>
                <div class="text-sm text-gray-500">Seed runs</div>
            </div>
        </div>

        <div class="flex gap-3 mb-6">
            <form method="POST" action="{{ route('soc.ai.knowledge-seed.seed') }}">
                @csrf
                <input type="hidden" name="dry_run" value="0">
                <button class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Seed Knowledge Base</button>
            </form>
            <form method="POST" action="{{ route('soc.ai.knowledge-seed.seed') }}">
                @csrf
                <input type="hidden" name="dry_run" value="1">
                <button class="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">Dry Run</button>
            </form>
        </div>

        @if($history->count())
        <div class="bg-white shadow rounded p-5 mb-6">
            <h3 class="font-semibold mb-3">Recent Seed Runs</h3>
            <table class="w-full text-sm">
                <thead><tr class="border-b"><th class="text-left py-1">Run ID</th><th class="py-1">Total</th><th class="py-1">Seeded</th><th class="py-1">Skipped</th><th class="py-1">Failed</th><th class="py-1">Mode</th><th class="py-1">Outcome</th><th class="text-left py-1">Date</th></tr></thead>
                <tbody>
                @foreach($history as $row)
                <tr class="border-b">
                    <td class="py-1 font-mono text-xs">{{ $row->run_id }}</td>
                    <td class="py-1 text-center">{{ $row->fixtures_total }}</td>
                    <td class="py-1 text-center text-green-600">{{ $row->fixtures_seeded }}</td>
                    <td class="py-1 text-center text-gray-500">{{ $row->fixtures_skipped }}</td>
                    <td class="py-1 text-center {{ $row->fixtures_failed > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ $row->fixtures_failed }}</td>
                    <td class="py-1 text-center text-xs">{{ $row->dry_run ? 'DRY-RUN' : 'WRITE' }}</td>
                    <td class="py-1 text-center"><span class="px-2 py-0.5 rounded text-xs {{ $row->outcome === 'DONE' ? 'bg-green-100 text-green-700' : ($row->outcome === 'DRY_RUN' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">{{ $row->outcome }}</span></td>
                    <td class="py-1 text-xs text-gray-500">{{ $row->created_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <div class="bg-white shadow rounded p-5">
            <h3 class="font-semibold mb-3">Fixtures ({{ $fixtures->count() }})</h3>
            <table class="w-full text-sm">
                <thead><tr class="border-b"><th class="text-left py-1">ID</th><th class="text-left py-1">Title</th><th class="py-1">Category</th></tr></thead>
                <tbody>
                @foreach($fixtures as $f)
                <tr class="border-b">
                    <td class="py-1 font-mono text-xs">{{ $f->fixture_id }}</td>
                    <td class="py-1 text-sm">{{ $f->title }}</td>
                    <td class="py-1 text-center"><span class="px-2 py-0.5 rounded text-xs bg-blue-50 text-blue-700">{{ $f->category }}</span></td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
