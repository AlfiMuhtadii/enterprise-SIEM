<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Tenant Strict Mode Readiness</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4">
        @if(session('status'))
            <div class="mb-4 p-3 bg-green-100 text-green-800 rounded">{{ session('status') }}</div>
        @endif

        <div class="flex justify-between items-center mb-6">
            <p class="text-gray-600">Advisory gate check for enabling <code>XDR_TENANT_STRICT_MODE=true</code>.</p>
            <form method="POST" action="{{ route('soc.tenant.strict-mode-readiness.assess') }}">
                @csrf
                <button class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Run Assessment</button>
            </form>
        </div>

        @if($latest)
        <div class="bg-white shadow rounded p-5 mb-6">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <h3 class="font-semibold text-lg">Latest Assessment</h3>
                    <p class="text-sm text-gray-500">{{ $latest->assessment_id }} — {{ $latest->created_at }}</p>
                </div>
                <span class="px-3 py-1 rounded text-sm font-medium
                    {{ $latest->overall_status === 'READY' ? 'bg-green-100 text-green-800' : ($latest->overall_status === 'WARN' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">
                    {{ $latest->overall_status }}
                </span>
            </div>
            <div class="grid grid-cols-4 gap-4 mb-4">
                <div class="text-center"><div class="text-2xl font-bold text-green-600">{{ $latest->gates_passed }}</div><div class="text-xs text-gray-500">PASS</div></div>
                <div class="text-center"><div class="text-2xl font-bold text-yellow-600">{{ $latest->gates_warned }}</div><div class="text-xs text-gray-500">WARN</div></div>
                <div class="text-center"><div class="text-2xl font-bold text-red-600">{{ $latest->gates_failed }}</div><div class="text-xs text-gray-500">FAIL</div></div>
                <div class="text-center"><div class="text-2xl font-bold">{{ number_format($latest->readiness_score * 100, 1) }}%</div><div class="text-xs text-gray-500">Score</div></div>
            </div>
            <table class="w-full text-sm">
                <thead><tr class="border-b"><th class="text-left py-1">Gate</th><th class="text-left py-1">Name</th><th class="py-1">Result</th><th class="text-left py-1">Detail</th></tr></thead>
                <tbody>
                @foreach($gates as $gate)
                <tr class="border-b">
                    <td class="py-1 font-mono text-xs">{{ $gate->gate_id }}</td>
                    <td class="py-1 text-xs">{{ $gate->gate_name }}</td>
                    <td class="py-1 text-center">
                        <span class="px-2 py-0.5 rounded text-xs {{ $gate->result === 'PASS' ? 'bg-green-100 text-green-700' : ($gate->result === 'WARN' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                            {{ $gate->result }}
                        </span>
                    </td>
                    <td class="py-1 text-xs text-gray-600">{{ $gate->detail }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div class="bg-gray-50 border rounded p-6 text-center text-gray-500 mb-6">No assessments yet. Run one to check readiness.</div>
        @endif

        @if($history->count() > 1)
        <div class="bg-white shadow rounded p-5">
            <h3 class="font-semibold mb-3">Recent History</h3>
            <table class="w-full text-sm">
                <thead><tr class="border-b"><th class="text-left py-1">Assessment ID</th><th class="py-1">Score</th><th class="py-1">Status</th><th class="text-left py-1">Date</th></tr></thead>
                <tbody>
                @foreach($history->skip(1)->take(9) as $row)
                <tr class="border-b">
                    <td class="py-1 font-mono text-xs">{{ $row->assessment_id }}</td>
                    <td class="py-1 text-center">{{ number_format($row->readiness_score * 100, 1) }}%</td>
                    <td class="py-1 text-center"><span class="px-2 py-0.5 rounded text-xs {{ $row->overall_status === 'READY' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ $row->overall_status }}</span></td>
                    <td class="py-1 text-xs text-gray-500">{{ $row->created_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</x-app-layout>
