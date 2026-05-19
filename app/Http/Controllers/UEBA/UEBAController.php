<?php

namespace App\Http\Controllers\UEBA;

use App\Http\Controllers\Controller;
use App\Models\BaselineAnomalyScore;
use App\Models\EntityBehaviorBaseline;
use App\Models\PeerGroupProfile;
use App\Services\UEBABaselineService;
use Illuminate\Http\Request;

/**
 * UEBA Phase 1 UI controller — explainable behavioral baseline analytics.
 *
 * All views display the advisory disclaimer:
 * "Behavioral analytics are advisory-only and explainable.
 *  No automatic enforcement is executed."
 *
 * No autonomous enforcement, no account suspension, no host isolation.
 */
class UEBAController extends Controller
{
    public function __construct(private readonly UEBABaselineService $uebaService) {}

    // -----------------------------------------------------------------------
    // 1. UEBA Dashboard — overview of anomaly volume and top anomalous entities
    // -----------------------------------------------------------------------

    public function dashboard()
    {
        $topUsers   = $this->uebaService->getTopAnomalousEntities('user', 10);
        $topHosts   = $this->uebaService->getTopAnomalousEntities('host', 10);
        $volumeTrend = $this->uebaService->getAnomalyVolumeTrend(7);

        $stats = [
            'total_baselines'  => EntityBehaviorBaseline::count(),
            'total_anomalies'  => BaselineAnomalyScore::where('scored_at', '>=', now()->subDays(7))->count(),
            'high_confidence'  => BaselineAnomalyScore::where('confidence', '>=', 0.75)
                ->where('scored_at', '>=', now()->subDays(7))->count(),
            'peer_groups'      => PeerGroupProfile::count(),
            'anomaly_types'    => BaselineAnomalyScore::select('anomaly_type')
                ->where('scored_at', '>=', now()->subDays(7))
                ->distinct()->pluck('anomaly_type'),
        ];

        return view('ueba.dashboard', compact('topUsers', 'topHosts', 'volumeTrend', 'stats'));
    }

    // -----------------------------------------------------------------------
    // 2. Baseline Profile View — single entity, all dimensions
    // -----------------------------------------------------------------------

    public function baselineProfile(Request $request)
    {
        $entityKey  = $request->input('entity_key', '');
        $entityType = $request->input('entity_type', 'user');

        $profile = null;
        if ($entityKey) {
            $profile = $this->uebaService->buildBaselineProfile($entityKey, $entityType);
        }

        $entityTypes = EntityBehaviorBaseline::ENTITY_TYPES;
        $dimensions  = EntityBehaviorBaseline::DIMENSIONS;

        return view('ueba.baseline-profile', compact('profile', 'entityKey', 'entityType', 'entityTypes', 'dimensions'));
    }

    // -----------------------------------------------------------------------
    // 3. Anomaly Score Explorer — filterable list of all scored anomalies
    // -----------------------------------------------------------------------

    public function anomalyExplorer(Request $request)
    {
        $anomalyType = $request->input('anomaly_type');
        $entityType  = $request->input('entity_type');
        $minConf     = (float) $request->input('min_confidence', 0.0);
        $days        = min((int) $request->input('days', 7), 30);

        $query = BaselineAnomalyScore::where('scored_at', '>=', now()->subDays($days))
            ->where('is_advisory', true)
            ->orderByDesc('scored_at');

        if ($anomalyType) {
            $query->where('anomaly_type', $anomalyType);
        }
        if ($entityType) {
            $query->where('entity_type', $entityType);
        }
        if ($minConf > 0) {
            $query->where('confidence', '>=', $minConf);
        }

        $scores      = $query->limit(200)->get();
        $anomalyTypes = BaselineAnomalyScore::ANOMALY_TYPES;
        $entityTypes  = EntityBehaviorBaseline::ENTITY_TYPES;

        return view('ueba.anomaly-explorer', compact('scores', 'anomalyTypes', 'entityTypes', 'anomalyType', 'entityType', 'minConf', 'days'));
    }

    // -----------------------------------------------------------------------
    // 4. Peer Group Comparison View
    // -----------------------------------------------------------------------

    public function peerGroupComparison(Request $request)
    {
        $groupKey  = $request->input('peer_group_key');
        $groupType = $request->input('group_type');

        $query = PeerGroupProfile::orderBy('group_type')->orderBy('group_label');
        if ($groupType) {
            $query->where('group_type', $groupType);
        }
        $groups = $query->limit(100)->get();

        $selectedGroup = $groupKey
            ? PeerGroupProfile::where('peer_group_key', $groupKey)->first()
            : null;

        $groupTypes = PeerGroupProfile::GROUP_TYPES;

        return view('ueba.peer-group', compact('groups', 'selectedGroup', 'groupKey', 'groupType', 'groupTypes'));
    }

    // -----------------------------------------------------------------------
    // 5. Entity Baseline History — anomaly timeline for a specific entity
    // -----------------------------------------------------------------------

    public function entityBaselineHistory(Request $request)
    {
        $entityKey  = $request->input('entity_key', '');
        $entityType = $request->input('entity_type', 'user');
        $limit      = min((int) $request->input('limit', 100), 200);

        $history  = collect();
        $baselines = collect();
        if ($entityKey) {
            $history   = $this->uebaService->getAnomalyHistory($entityKey, $entityType, $limit);
            $baselines = EntityBehaviorBaseline::where('entity_key', $entityKey)
                ->where('entity_type', $entityType)->get();
        }

        $entityTypes = EntityBehaviorBaseline::ENTITY_TYPES;

        return view('ueba.entity-history', compact('history', 'baselines', 'entityKey', 'entityType', 'entityTypes'));
    }

    // -----------------------------------------------------------------------
    // 6. Baseline Drift Monitor — entities with high baseline variance
    // -----------------------------------------------------------------------

    public function baselineDriftMonitor()
    {
        $driftData   = $this->uebaService->getBaselineDriftSummary(100);
        $dimensions  = EntityBehaviorBaseline::DIMENSIONS;
        $byDimension = [];
        foreach ($dimensions as $dim) {
            $byDimension[$dim] = $driftData->where('dimension', $dim)->count();
        }

        return view('ueba.drift-monitor', compact('driftData', 'dimensions', 'byDimension'));
    }

    // -----------------------------------------------------------------------
    // 7. UEBA Risk Contribution View — how UEBA factors feed entity risk score
    // -----------------------------------------------------------------------

    public function riskContribution(Request $request)
    {
        $entityKey  = $request->input('entity_key', '');
        $entityType = $request->input('entity_type', 'user');

        $contributions = collect();
        $recentScores  = collect();
        if ($entityKey) {
            $recentScores = BaselineAnomalyScore::where('entity_key', $entityKey)
                ->where('entity_type', $entityType)
                ->where('scored_at', '>=', now()->subDays(7))
                ->where('confidence', '>=', 0.75)
                ->orderByDesc('scored_at')
                ->get();

            // Map each anomaly type to its risk factor
            $factorMap = [
                'unusual_login_time'                    => 'unusual_activity_time_factor',
                'unusual_source_ip_diversity'           => 'baseline_anomaly_factor',
                'abnormal_failed_login_ratio'           => 'baseline_anomaly_factor',
                'unusual_saas_action_frequency'         => 'baseline_anomaly_factor',
                'unusual_process_execution_frequency'   => 'baseline_anomaly_factor',
                'abnormal_network_destination_frequency'=> 'baseline_anomaly_factor',
                'abnormal_bytes_out'                    => 'abnormal_data_volume_factor',
                'unusual_host_usage'                    => 'baseline_anomaly_factor',
                'peer_group_behavior_deviation'         => 'peer_deviation_factor',
            ];

            $contributions = $recentScores->map(fn ($s) => [
                'anomaly_type'    => $s->anomaly_type,
                'dimension'       => $s->dimension,
                'risk_factor'     => $factorMap[$s->anomaly_type] ?? 'baseline_anomaly_factor',
                'confidence'      => $s->confidence,
                'observed_value'  => $s->observed_value,
                'baseline_value'  => $s->baseline_value,
                'deviation'       => $s->deviation,
                'scored_at'       => $s->scored_at,
                'is_advisory'     => true,
            ]);
        }

        $entityTypes = EntityBehaviorBaseline::ENTITY_TYPES;

        return view('ueba.risk-contribution', compact('contributions', 'recentScores', 'entityKey', 'entityType', 'entityTypes'));
    }
}
