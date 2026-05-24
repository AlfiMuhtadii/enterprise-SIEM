<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Release Candidate Stabilization — Dashboard
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-4 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> Release stabilization workflows are bounded, replay-safe, and audit-visible.
            No destructive deployment automation, uncontrolled feature expansion, or autonomous remediation is executed.
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-blue-600">{{ $stats['total_rc_manifests'] }}</div>
                <div class="text-xs text-gray-500 mt-1">RC Manifests</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-indigo-600">{{ $stats['frozen_rcs'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Frozen RCs</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-green-600">{{ $stats['verified_artifacts'] }}/{{ $stats['total_artifacts'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Artifacts Verified</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-red-600">{{ $stats['critical_blockers'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Critical Blockers</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-purple-600">{{ $stats['stabilization_passed'] }}/{{ $stats['stabilization_runs'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Stab. Runs Passed</div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white rounded shadow p-4">
                <h3 class="font-semibold text-gray-700 mb-2">Recent RC Manifests</h3>
                @forelse($recentRcs as $rc)
                    <div class="text-sm border-b py-1 flex justify-between">
                        <span class="font-mono text-xs text-gray-600">{{ $rc->rc_version }}</span>
                        <span class="text-xs {{ $rc->rc_state === 'frozen' ? 'text-indigo-600' : ($rc->rc_state === 'released' ? 'text-green-600' : 'text-gray-500') }}">
                            {{ $rc->rc_state }}
                        </span>
                    </div>
                @empty
                    <div class="text-xs text-gray-400">No RC manifests yet.</div>
                @endforelse
            </div>

            <div class="bg-white rounded shadow p-4">
                <h3 class="font-semibold text-gray-700 mb-2">Open Blockers</h3>
                @forelse($openBlockers as $blocker)
                    <div class="text-sm border-b py-1 flex justify-between">
                        <span class="text-xs text-gray-600">{{ $blocker->blocker_category }}</span>
                        <span class="text-xs font-medium {{ $blocker->blocker_severity === 'critical' ? 'text-red-600' : 'text-yellow-600' }}">
                            {{ $blocker->blocker_severity }}
                        </span>
                    </div>
                @empty
                    <div class="text-xs text-gray-400">No open blockers.</div>
                @endforelse
            </div>

            <div class="bg-white rounded shadow p-4">
                <h3 class="font-semibold text-gray-700 mb-2">Recent Pilot Prep Runs</h3>
                @forelse($recentPilotRuns as $run)
                    <div class="text-sm border-b py-1 flex justify-between">
                        <span class="text-xs text-gray-600">{{ $run->preparation_scope }}</span>
                        <span class="text-xs {{ $run->preparation_state === 'passed' ? 'text-green-600' : 'text-red-500' }}">
                            {{ $run->preparation_state }}
                        </span>
                    </div>
                @empty
                    <div class="text-xs text-gray-400">No pilot prep runs yet.</div>
                @endforelse
            </div>
        </div>

        <div class="bg-white rounded shadow p-4">
            <h3 class="font-semibold text-gray-700 mb-3">Navigation</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <a href="{{ route('release-stab.freeze') }}" class="text-sm text-blue-600 hover:underline">Feature Freeze</a>
                <a href="{{ route('release-stab.artifacts') }}" class="text-sm text-blue-600 hover:underline">Artifact Explorer</a>
                <a href="{{ route('release-stab.pilot') }}" class="text-sm text-blue-600 hover:underline">Pilot Readiness</a>
                <a href="{{ route('release-stab.validation') }}" class="text-sm text-blue-600 hover:underline">Operational Validation</a>
                <a href="{{ route('release-stab.reproducibility') }}" class="text-sm text-blue-600 hover:underline">Reproducibility</a>
                <a href="{{ route('release-stab.blockers') }}" class="text-sm text-blue-600 hover:underline">Blocker Explorer</a>
                <a href="{{ route('release-stab.stabilization') }}" class="text-sm text-blue-600 hover:underline">Stabilization Viewer</a>
                <a href="{{ route('release-stab.audit') }}" class="text-sm text-blue-600 hover:underline">Audit Timeline</a>
            </div>
        </div>

    </div>
</x-app-layout>
