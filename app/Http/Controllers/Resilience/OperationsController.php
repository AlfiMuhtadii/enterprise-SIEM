<?php

namespace App\Http\Controllers\Resilience;

use App\Http\Controllers\Controller;
use App\Models\DlqReplayJob;
use App\Models\RetentionPolicy;
use App\Services\BackupRecoveryService;
use App\Services\DlqReplayService;
use App\Services\OperationsHealthService;
use App\Services\RetentionGovernanceService;
use Illuminate\Http\Request;

class OperationsController extends Controller
{
    public function __construct(
        private readonly OperationsHealthService    $health,
        private readonly RetentionGovernanceService $retention,
        private readonly BackupRecoveryService      $backup,
        private readonly DlqReplayService           $dlq,
    ) {}

    public function healthDashboard()
    {
        $score    = $this->health->scoreOverallHealth();
        $snapshots= $this->health->getLatestHealthSnapshots();
        $warnings = $this->health->getOperationalWarnings();
        $pressure = $this->health->getRecentStreamPressure(20);
        return view('operations.health-dashboard', compact('score', 'snapshots', 'warnings', 'pressure'));
    }

    public function queueHealth()
    {
        $lag      = $this->health->getRecentQueueLag(50);
        $pressure = $this->health->getRecentStreamPressure(30);
        $degraded = $this->health->getDegradedServices();
        return view('operations.queue-health', compact('lag', 'pressure', 'degraded'));
    }

    public function retentionCenter()
    {
        $policies   = $this->retention->getPolicies();
        $executions = $this->retention->getRecentExecutions(20);
        $audit      = $this->retention->getAuditTrail(30);
        $domains    = RetentionPolicy::ELIGIBLE_DOMAINS;
        return view('operations.retention-center', compact('policies', 'executions', 'audit', 'domains'));
    }

    public function backupRecovery()
    {
        $backups    = $this->backup->getBackups(20);
        $validations= $this->backup->getValidationHistory(30);
        $summary    = $this->backup->getCheckpointIntegritySummary();
        return view('operations.backup-recovery', compact('backups', 'validations', 'summary'));
    }

    public function dlqReplayCenter()
    {
        $jobs    = $this->dlq->getJobs(30);
        $pending = $this->dlq->getPendingJobs();
        return view('operations.dlq-replay', compact('jobs', 'pending'));
    }

    public function deploymentGraph()
    {
        $graph    = $this->health->buildDependencyGraph();
        $snapshots= $this->health->getLatestHealthSnapshots();
        return view('operations.deployment-graph', compact('graph', 'snapshots'));
    }

    public function warningsTimeline()
    {
        $warnings = $this->health->getOperationalWarnings();
        $audit    = $this->retention->getAuditTrail(20);
        $health   = $this->health->getLatestHealthSnapshots();
        return view('operations.warnings-timeline', compact('warnings', 'audit', 'health'));
    }

    // -----------------------------------------------------------------------
    // Form actions
    // -----------------------------------------------------------------------

    public function previewRetention(Request $request, string $policyId)
    {
        $policy  = RetentionPolicy::where('policy_id', $policyId)->firstOrFail();
        $preview = $this->retention->previewRetention($policy);
        return redirect()->route('operations.retention')
            ->with('preview', $preview);
    }

    public function runDryRun(Request $request, string $policyId)
    {
        $policy = RetentionPolicy::where('policy_id', $policyId)->firstOrFail();
        $exec   = $this->retention->executeDryRun($policy, $request->user());
        return redirect()->route('operations.retention')
            ->with('success', "Dry-run completed: {$exec->rows_eligible} rows eligible (0 deleted).");
    }

    public function createDlqJob(Request $request)
    {
        $request->validate([
            'topic'       => 'required|string|max:120',
            'from_offset' => 'required|integer|min:0',
            'batch_limit' => 'required|integer|min:1|max:500',
        ]);

        $job = $this->dlq->createReplayJob([
            'topic'        => $request->topic,
            'from_offset'  => $request->from_offset,
            'batch_limit'  => $request->batch_limit,
        ], $request->user());

        return redirect()->route('operations.dlq')
            ->with('success', "DLQ job created: {$job->job_id} (dry-run mode).");
    }

    public function simulateDlqJob(Request $request, string $jobId)
    {
        $job = DlqReplayJob::where('job_id', $jobId)->firstOrFail();
        $this->dlq->simulateReplay($job);
        return redirect()->route('operations.dlq')
            ->with('success', "Simulation complete for {$jobId}.");
    }

    public function cancelDlqJob(Request $request, string $jobId)
    {
        $job = DlqReplayJob::where('job_id', $jobId)->firstOrFail();
        $this->dlq->cancelJob($job, $request->user());
        return redirect()->route('operations.dlq')
            ->with('success', "Job {$jobId} cancelled.");
    }
}
