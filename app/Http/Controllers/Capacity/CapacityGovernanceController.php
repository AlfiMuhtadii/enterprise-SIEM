<?php

namespace App\Http\Controllers\Capacity;

use App\Http\Controllers\Controller;
use App\Models\CapacityProjectionRun;
use App\Models\CardinalityPressureReport;
use App\Models\InfrastructureCostEstimate;
use App\Models\PartitionPressureSnapshot;
use App\Models\QueryPerformanceSnapshot;
use App\Models\ReplayAmplificationReport;
use App\Models\ReplayEconomicsRun;
use App\Models\StorageCapacitySnapshot;
use App\Models\TelemetryCapacitySnapshot;
use App\Services\CapacityGovernanceService;
use Illuminate\View\View;

/**
 * Capacity, Performance & Cost Governance UI controller.
 * Visibility controls only — no autonomous scaling, destructive retention purge,
 * or hidden infrastructure mutation.
 */
class CapacityGovernanceController extends Controller
{
    public function __construct(
        private CapacityGovernanceService $capacity,
    ) {}

    public function capacityDashboard(): View
    {
        $stats = $this->capacity->getDashboardStats();
        return view('capacity.capacity-dashboard', compact('stats'));
    }

    public function replayEconomicsExplorer(): View
    {
        $runs     = ReplayEconomicsRun::orderByDesc('created_at')->limit(100)->get();
        $unbounded= $runs->where('is_bounded', false);
        return view('capacity.replay-economics-explorer', compact('runs', 'unbounded'));
    }

    public function queryPerformanceViewer(): View
    {
        $snapshots = QueryPerformanceSnapshot::orderByDesc('created_at')->limit(200)->get();
        $degraded  = $snapshots->whereIn('latency_state', ['degraded', 'critical']);
        $byBackend = $snapshots->groupBy('backend');
        return view('capacity.query-performance-viewer', compact('snapshots', 'degraded', 'byBackend'));
    }

    public function storageCapacityDashboard(): View
    {
        $snapshots = StorageCapacitySnapshot::orderByDesc('created_at')->limit(100)->get();
        $latest    = $snapshots->unique('backend');
        $critical  = $snapshots->where('capacity_state', StorageCapacitySnapshot::STATE_CRITICAL);
        return view('capacity.storage-capacity-dashboard', compact('snapshots', 'latest', 'critical'));
    }

    public function cardinalityPressureExplorer(): View
    {
        $reports = CardinalityPressureReport::orderByDesc('created_at')->limit(200)->get();
        $byType  = $reports->groupBy('pressure_type');
        $critical= $reports->where('severity', 'critical');
        return view('capacity.cardinality-pressure-explorer', compact('reports', 'byType', 'critical'));
    }

    public function replayAmplificationViewer(): View
    {
        $reports  = ReplayAmplificationReport::orderByDesc('created_at')->limit(200)->get();
        $exceeded = $reports->where('exceeds_safe_threshold', true);
        $avgRatio = $reports->avg('amplification_ratio') ?? 0.0;
        return view('capacity.replay-amplification-viewer', compact('reports', 'exceeded', 'avgRatio'));
    }

    public function partitionPressureMonitor(): View
    {
        $partitions = PartitionPressureSnapshot::orderByDesc('updated_at')->limit(200)->get();
        $byHealth   = $partitions->groupBy('health_state');
        return view('capacity.partition-pressure-monitor', compact('partitions', 'byHealth'));
    }

    public function capacityProjectionConsole(): View
    {
        $projections = CapacityProjectionRun::orderByDesc('created_at')->limit(50)->get();
        $byScope     = $projections->groupBy('scope');
        return view('capacity.capacity-projection-console', compact('projections', 'byScope'));
    }

    public function infrastructureCostDashboard(): View
    {
        $estimates = InfrastructureCostEstimate::orderByDesc('created_at')->limit(100)->get();
        $byScope   = $estimates->groupBy('scope');
        $totalDaily= $estimates->where('scope', 'total')->first();
        return view('capacity.infrastructure-cost-dashboard', compact('estimates', 'byScope', 'totalDaily'));
    }
}
