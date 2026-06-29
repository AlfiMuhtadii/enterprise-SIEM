<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Security Hardening Evidence Freeze</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-4 mb-6">
            <strong>Advisory Only</strong> — displays read-only evidence of security hardening controls.
            No enforcement changes are made here.
        </div>
        @if($latestRun)
        <div class="bg-white shadow rounded p-6 mb-6">
            <h3 class="text-lg font-medium mb-4">Latest Freeze Run</h3>
            <dl class="grid grid-cols-2 gap-4">
                <div><dt class="text-sm text-gray-500">Run ID</dt><dd class="font-mono text-xs">{{ $latestRun->run_id }}</dd></div>
                <div><dt class="text-sm text-gray-500">State</dt><dd>{{ strtoupper($latestRun->run_state) }}</dd></div>
                <div><dt class="text-sm text-gray-500">Controls Passed</dt><dd class="text-green-600 font-semibold">{{ $latestRun->controls_passed }}</dd></div>
                <div><dt class="text-sm text-gray-500">Controls Failed</dt><dd class="text-red-600 font-semibold">{{ $latestRun->controls_failed }}</dd></div>
                @if($latestCoverage)
                <div><dt class="text-sm text-gray-500">Coverage Score</dt><dd>{{ number_format($latestCoverage->overall_score * 100, 1) }}%</dd></div>
                <div><dt class="text-sm text-gray-500">Threshold Met</dt><dd>{{ $latestCoverage->meets_pass_threshold ? '✓ PASS' : '✗ BELOW THRESHOLD' }}</dd></div>
                @endif
            </dl>
        </div>
        @else
        <p class="text-gray-500">No freeze runs recorded yet. Run <code class="bg-gray-100 px-1">php artisan security:hardening-freeze</code>.</p>
        @endif
        <div class="flex gap-4 mt-4 text-sm">
            <a href="{{ route('security-hardening-freeze.runs') }}" class="text-blue-600 hover:underline">All Runs</a>
            <a href="{{ route('security-hardening-freeze.controls') }}" class="text-blue-600 hover:underline">Controls</a>
            <a href="{{ route('security-hardening-freeze.coverage') }}" class="text-blue-600 hover:underline">Coverage Reports</a>
            <a href="{{ route('security-hardening-freeze.delta') }}" class="text-blue-600 hover:underline">Delta Reports</a>
        </div>
    </div>
</x-app-layout>
