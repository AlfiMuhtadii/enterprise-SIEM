<?php

namespace App\Http\Controllers\Reliability;

use App\Http\Controllers\Controller;
use App\Models\DegradedModeEvent;
use App\Models\DuplicateEventReport;
use App\Models\IdempotencyValidationRecord;
use App\Models\RecoveryValidationRun;
use App\Models\ReplayThrottleState;
use App\Models\StoragePressureSnapshot;
use App\Models\StreamConsumerLagSnapshot;
use App\Models\SystemWorkerHeartbeat;
use App\Services\DistributedReliabilityService;
use Illuminate\View\View;

/**
 * HA / Distributed Reliability UI controller.
 * Operational safeguards only. No autonomous remediation, destructive replay,
 * or hidden data deletion.
 */
class DistributedReliabilityController extends Controller
{
    public function __construct(
        private DistributedReliabilityService $reliability,
    ) {}

    public function reliabilityDashboard(): View
    {
        $stats = $this->reliability->getDashboardStats();
        return view('reliability.reliability-dashboard', compact('stats'));
    }

    public function workerHealthExplorer(): View
    {
        $workers = SystemWorkerHeartbeat::orderByDesc('last_heartbeat_at')->limit(100)->get();
        $byState = $workers->groupBy('health_state');
        return view('reliability.worker-health-explorer', compact('workers', 'byState'));
    }

    public function consumerLagMonitor(): View
    {
        $snapshots = StreamConsumerLagSnapshot::orderByDesc('created_at')->limit(200)->get();
        $byGroup   = $snapshots->groupBy('consumer_group');
        $critical  = $snapshots->where('pressure_state', 'critical');
        return view('reliability.consumer-lag-monitor', compact('snapshots', 'byGroup', 'critical'));
    }

    public function replayThrottleConsole(): View
    {
        $throttles = ReplayThrottleState::orderByDesc('updated_at')->get();
        $exhausted = $throttles->where('state', ReplayThrottleState::STATE_EXHAUSTED);
        $throttled = $throttles->where('state', ReplayThrottleState::STATE_THROTTLED);
        return view('reliability.replay-throttle-console', compact('throttles', 'exhausted', 'throttled'));
    }

    public function idempotencyExplorer(): View
    {
        $records   = IdempotencyValidationRecord::orderByDesc('updated_at')->limit(200)->get();
        $duplicates= $records->where('is_duplicate', true);
        return view('reliability.idempotency-explorer', compact('records', 'duplicates'));
    }

    public function duplicateEventReports(): View
    {
        $reports  = DuplicateEventReport::orderByDesc('created_at')->limit(200)->get();
        $bySev    = $reports->groupBy('severity');
        return view('reliability.duplicate-event-reports', compact('reports', 'bySev'));
    }

    public function storagePressureDashboard(): View
    {
        $snapshots = StoragePressureSnapshot::orderByDesc('created_at')->limit(200)->get();
        $latestPerBackend = $snapshots->unique('backend');
        return view('reliability.storage-pressure-dashboard', compact('snapshots', 'latestPerBackend'));
    }

    public function degradedModeTimeline(): View
    {
        $events  = DegradedModeEvent::orderByDesc('created_at')->limit(200)->get();
        $byService= $events->groupBy('service_name');
        return view('reliability.degraded-mode-timeline', compact('events', 'byService'));
    }

    public function recoveryValidationViewer(): View
    {
        $runs    = RecoveryValidationRun::orderByDesc('created_at')->limit(100)->get();
        $passRate= $runs->count() > 0
            ? round($runs->where('status', RecoveryValidationRun::STATUS_PASSED)->count() / $runs->count(), 4)
            : 0.0;
        return view('reliability.recovery-validation-viewer', compact('runs', 'passRate'));
    }
}
