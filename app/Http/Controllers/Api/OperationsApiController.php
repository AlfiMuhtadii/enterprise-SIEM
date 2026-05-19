<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DlqReplayJob;
use App\Models\RetentionPolicy;
use App\Services\BackupRecoveryService;
use App\Services\DlqReplayService;
use App\Services\OperationsHealthService;
use App\Services\RetentionGovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationsApiController extends Controller
{
    public function __construct(
        private readonly OperationsHealthService    $health,
        private readonly RetentionGovernanceService $retention,
        private readonly BackupRecoveryService      $backup,
        private readonly DlqReplayService           $dlq,
    ) {}

    public function getHealthSummary(): JsonResponse
    {
        return response()->json([
            'score'         => $this->health->scoreOverallHealth(),
            'services'      => $this->health->getLatestHealthSnapshots(),
            'warnings_count'=> count($this->health->getOperationalWarnings()),
            'advisory_note' => 'Operational hardening and recovery tooling only. No autonomous infrastructure mutation.',
        ]);
    }

    public function getQueueLag(): JsonResponse
    {
        return response()->json([
            'lag'       => $this->health->getRecentQueueLag(30),
            'pressure'  => $this->health->getRecentStreamPressure(20),
        ]);
    }

    public function getDependencyGraph(): JsonResponse
    {
        $graph    = $this->health->buildDependencyGraph();
        $snapshots= $this->health->getLatestHealthSnapshots();

        $statusByService = $snapshots->keyBy('service_name')->map(fn ($s) => $s->health_state);
        foreach ($graph['nodes'] as &$node) {
            $node['health_state'] = $statusByService[$node['id']] ?? 'unknown';
        }

        return response()->json($graph);
    }

    public function previewRetention(string $policyId): JsonResponse
    {
        $policy  = RetentionPolicy::where('policy_id', $policyId)->firstOrFail();
        $preview = $this->retention->previewRetention($policy);
        return response()->json($preview);
    }

    public function getDlqJobs(): JsonResponse
    {
        return response()->json([
            'jobs'    => $this->dlq->getJobs(20),
            'pending' => $this->dlq->getPendingJobs(),
        ]);
    }

    public function getDlqPreview(string $jobId): JsonResponse
    {
        $job = DlqReplayJob::where('job_id', $jobId)->firstOrFail();
        return response()->json($this->dlq->previewReplay($job));
    }

    public function getBackupSummary(): JsonResponse
    {
        return response()->json([
            'summary'    => $this->backup->getCheckpointIntegritySummary(),
            'recent'     => $this->backup->getBackups(10),
        ]);
    }

    public function getWarnings(): JsonResponse
    {
        return response()->json([
            'warnings'      => $this->health->getOperationalWarnings(),
            'advisory_note' => 'No autonomous remediation. All warnings require operator review.',
        ]);
    }
}
