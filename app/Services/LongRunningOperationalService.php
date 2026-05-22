<?php

namespace App\Services;

use App\Models\OperationalValidationWindow;
use App\Models\TelemetryTrendReport;
use App\Models\AnalystBehaviorTrend;
use App\Models\FalsePositiveEvolutionReport;
use App\Models\OperationalDriftHistory;
use App\Models\GovernanceReportingRun;
use App\Models\ReplayDurabilityHistory;
use App\Models\InfrastructureStabilityReport;
use App\Models\ProductionGovernanceAudit;
use Illuminate\Support\Str;

class LongRunningOperationalService
{
    public const ADVISORY_ONLY = true;

    // Operational window types
    public const WINDOW_TYPES = ['7d', '14d', '30d'];

    // Thresholds
    public const MIN_CONTINUITY_PCT          = 0.95;
    public const MAX_DUPLICATE_RATE          = 0.01;
    public const MAX_QUEUE_LAG               = 100_000;
    public const MAX_REPLAY_AMPLIFICATION    = 3.0;
    public const MAX_STORAGE_PRESSURE        = 0.85;
    public const MAX_FATIGUE_SCORE           = 0.70;
    public const MAX_FP_RATE                 = 0.10;
    public const DRIFT_CRITICAL_THRESHOLD    = 0.50;
    public const DRIFT_ESCALATED_THRESHOLD   = 0.25;
    public const DRIFT_MONITORING_THRESHOLD  = 0.10;

    // =========================================================================
    // Operational validation windows
    // =========================================================================

    public function recordOperationalWindow(
        string $tenantId,
        string $windowType,
        array  $metrics = []
    ): OperationalValidationWindow {
        if (!in_array($windowType, self::WINDOW_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid window type: {$windowType}. Allowed: " . implode(', ', self::WINDOW_TYPES));
        }

        $continuity  = $metrics['telemetry_continuity_pct']    ?? 1.0;
        $replaySuc   = $metrics['replay_recovery_success_pct'] ?? 1.0;
        $queueLag    = $metrics['avg_queue_lag']                ?? 0.0;
        $storageGb   = $metrics['storage_growth_gb']            ?? 0.0;
        $restarts    = $metrics['worker_restart_count']         ?? 0;

        $criteriaMet = $continuity >= self::MIN_CONTINUITY_PCT
                    && $replaySuc  >= self::MIN_CONTINUITY_PCT
                    && $queueLag   <= self::MAX_QUEUE_LAG;

        $window = OperationalValidationWindow::create([
            'window_id'                   => 'ovw-' . Str::uuid(),
            'tenant_id'                   => $tenantId,
            'window_type'                 => $windowType,
            'window_start'                => $metrics['window_start'] ?? null,
            'window_end'                  => $metrics['window_end']   ?? null,
            'telemetry_continuity_pct'    => $continuity,
            'replay_recovery_success_pct' => $replaySuc,
            'avg_queue_lag'               => $queueLag,
            'storage_growth_gb'           => $storageGb,
            'worker_restart_count'        => $restarts,
            'criteria_met'                => $criteriaMet,
            'is_advisory'                 => true,
            'window_summary'              => $metrics ?: null,
        ]);

        $this->writeAudit($tenantId, 'window_created', 'system', 'success', "Operational window {$windowType} recorded");
        return $window;
    }

    // =========================================================================
    // Telemetry trend reports
    // =========================================================================

    public function recordTelemetryTrend(
        string $tenantId,
        string $windowType,
        array  $metrics = []
    ): TelemetryTrendReport {
        if (!in_array($windowType, self::WINDOW_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid window type: {$windowType}");
        }

        $continSlope  = $metrics['continuity_trend_slope']           ?? 0.0;
        $queueSlope   = $metrics['queue_lag_trend_slope']             ?? 0.0;
        $dupTrend     = $metrics['duplicate_rate_trend']              ?? 0.0;
        $backlogSlope = $metrics['replay_backlog_trend_slope']        ?? 0.0;
        $gapAccum     = $metrics['telemetry_gap_accumulation']        ?? 0.0;
        $storageRate  = $metrics['storage_growth_rate_gb_per_day']    ?? 0.0;

        $verdict = match(true) {
            $continSlope < -0.02 || $queueSlope > 0.05 => 'critical',
            $continSlope < -0.01 || $queueSlope > 0.02 => 'degrading',
            $continSlope > 0.005 && $queueSlope < 0    => 'improving',
            default                                     => 'stable',
        };

        $report = TelemetryTrendReport::create([
            'report_id'                      => 'ttr-' . Str::uuid(),
            'tenant_id'                      => $tenantId,
            'window_type'                    => $windowType,
            'continuity_trend_slope'         => $continSlope,
            'queue_lag_trend_slope'          => $queueSlope,
            'duplicate_rate_trend'           => $dupTrend,
            'replay_backlog_trend_slope'     => $backlogSlope,
            'telemetry_gap_accumulation'     => $gapAccum,
            'storage_growth_rate_gb_per_day' => $storageRate,
            'trend_verdict'                  => $verdict,
            'replay_safe'                    => true,
            'is_advisory'                    => true,
            'trend_data'                     => $metrics ?: null,
        ]);

        $this->writeAudit($tenantId, 'trend_analyzed', 'system', 'success', "Telemetry trend analyzed: {$verdict}");
        return $report;
    }

    // =========================================================================
    // Analyst behavior trends
    // =========================================================================

    public function recordAnalystBehaviorTrend(
        string $analystId,
        string $tenantId,
        string $windowType,
        array  $metrics = []
    ): AnalystBehaviorTrend {
        if (!in_array($windowType, self::WINDOW_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid window type: {$windowType}");
        }

        $fatigueScore = min(1.0, max(0.0, $metrics['fatigue_score'] ?? 0.0));
        $latencySlope = $metrics['latency_trend_slope'] ?? 0.0;

        $behaviorStable = $fatigueScore < self::MAX_FATIGUE_SCORE && $latencySlope < 0.05;

        return AnalystBehaviorTrend::create([
            'trend_id'                          => 'abt-' . Str::uuid(),
            'analyst_id'                        => $analystId,
            'tenant_id'                         => $tenantId,
            'window_type'                       => $windowType,
            'avg_latency_seconds'               => $metrics['avg_latency_seconds']               ?? 0.0,
            'latency_trend_slope'               => $latencySlope,
            'fatigue_score'                     => $fatigueScore,
            'escalation_quality_avg'            => $metrics['escalation_quality_avg']            ?? 0.0,
            'suppression_usage_rate'            => $metrics['suppression_usage_rate']            ?? 0.0,
            'recurring_dismissal_count'         => $metrics['recurring_dismissal_count']         ?? 0,
            'avg_investigation_duration_minutes'=> $metrics['avg_investigation_duration_minutes'] ?? 0.0,
            'behavior_stable'                   => $behaviorStable,
            'is_advisory'                       => true,
            'behavior_evidence'                 => $metrics ?: null,
        ]);
    }

    // =========================================================================
    // False-positive evolution
    // =========================================================================

    public function recordFpEvolution(
        string $tenantId,
        string $windowType,
        float  $fpRateStart,
        float  $fpRateEnd,
        array  $metrics = []
    ): FalsePositiveEvolutionReport {
        if (!in_array($windowType, self::WINDOW_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid window type: {$windowType}");
        }

        $fpSlope = ($fpRateEnd - $fpRateStart) / max(1, $this->windowDays($windowType));

        $verdict = match(true) {
            $fpRateEnd >= self::MAX_FP_RATE        => 'critical',
            $fpSlope > 0.002                       => 'worsening',
            $fpSlope < -0.002                      => 'improving',
            default                                => 'stable',
        };

        return FalsePositiveEvolutionReport::create([
            'report_id'                    => 'fpe-' . Str::uuid(),
            'tenant_id'                    => $tenantId,
            'window_type'                  => $windowType,
            'fp_rate_start'                => $fpRateStart,
            'fp_rate_end'                  => $fpRateEnd,
            'fp_trend_slope'               => $fpSlope,
            'suppression_effectiveness_avg'=> $metrics['suppression_effectiveness_avg'] ?? 0.0,
            'replay_disagreement_rate'     => $metrics['replay_disagreement_rate']      ?? 0.0,
            'confidence_drift_avg'         => $metrics['confidence_drift_avg']          ?? 0.0,
            'recurring_benign_count'       => $metrics['recurring_benign_count']        ?? 0,
            'fp_verdict'                   => $verdict,
            'is_advisory'                  => true,
            'evolution_evidence'           => $metrics ?: null,
        ]);
    }

    // =========================================================================
    // Operational drift history
    // =========================================================================

    public function recordDriftHistory(
        string $tenantId,
        string $windowType,
        array  $drifts = []
    ): OperationalDriftHistory {
        if (!in_array($windowType, self::WINDOW_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid window type: {$windowType}");
        }

        $fields = [
            'replay_amplification_drift'       => $drifts['replay_amplification_drift']       ?? 0.0,
            'queue_growth_drift'               => $drifts['queue_growth_drift']               ?? 0.0,
            'telemetry_growth_drift'           => $drifts['telemetry_growth_drift']           ?? 0.0,
            'analyst_overload_drift'           => $drifts['analyst_overload_drift']           ?? 0.0,
            'storage_pressure_drift'           => $drifts['storage_pressure_drift']           ?? 0.0,
            'infrastructure_degradation_drift' => $drifts['infrastructure_degradation_drift'] ?? 0.0,
            'graph_traversal_latency_drift'    => $drifts['graph_traversal_latency_drift']    ?? 0.0,
            'replay_latency_drift'             => $drifts['replay_latency_drift']             ?? 0.0,
        ];

        $compositeScore = array_sum($fields) / count($fields);

        $verdict = match(true) {
            $compositeScore >= self::DRIFT_CRITICAL_THRESHOLD  => 'critical',
            $compositeScore >= self::DRIFT_ESCALATED_THRESHOLD => 'escalated',
            $compositeScore >= self::DRIFT_MONITORING_THRESHOLD=> 'monitoring',
            default                                            => 'stable',
        };

        $record = OperationalDriftHistory::create(array_merge($fields, [
            'drift_id'             => 'odh-' . Str::uuid(),
            'tenant_id'            => $tenantId,
            'window_type'          => $windowType,
            'composite_drift_score'=> $compositeScore,
            'drift_verdict'        => $verdict,
            'is_advisory'          => true,
            'drift_breakdown'      => $drifts ?: null,
        ]));

        if ($verdict === 'critical') {
            $this->writeAudit($tenantId, 'drift_detected', 'system', 'degraded', "Critical drift detected: composite={$compositeScore}");
        }

        return $record;
    }

    // =========================================================================
    // Governance reporting runs
    // =========================================================================

    public function generateGovernanceReport(
        string $tenantId,
        string $reportType,
        string $windowType,
        array  $components = []
    ): GovernanceReportingRun {
        if (!in_array($reportType, GovernanceReportingRun::REPORT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid report type: {$reportType}");
        }

        if (!in_array($windowType, self::WINDOW_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid window type: {$windowType}");
        }

        $telPassing  = $components['telemetry_passing']        ?? true;
        $repPassing  = $components['replay_passing']           ?? true;
        $analStable  = $components['analyst_stable']           ?? true;
        $infraStable = $components['infrastructure_stable']    ?? true;
        $isoPass     = $components['tenant_isolation_passing'] ?? true;

        $healthScore = (($telPassing ? 1 : 0) + ($repPassing ? 1 : 0) + ($analStable ? 1 : 0) + ($infraStable ? 1 : 0) + ($isoPass ? 1 : 0)) / 5.0;

        $verdict = match(true) {
            $healthScore >= 1.0   => 'pass',
            $healthScore >= 0.80  => 'advisory',
            $healthScore >= 0.60  => 'degraded',
            default               => 'fail',
        };

        $run = GovernanceReportingRun::create([
            'run_id'                   => 'grr-' . Str::uuid(),
            'tenant_id'                => $tenantId,
            'report_type'              => $reportType,
            'window_type'              => $windowType,
            'overall_health_score'     => $healthScore,
            'telemetry_passing'        => $telPassing,
            'replay_passing'           => $repPassing,
            'analyst_stable'           => $analStable,
            'infrastructure_stable'    => $infraStable,
            'tenant_isolation_passing' => $isoPass,
            'governance_verdict'       => $verdict,
            'is_advisory'              => true,
            'report_summary'           => $components ?: null,
        ]);

        $this->writeAudit($tenantId, 'report_generated', 'system', 'success', "Governance report generated: {$reportType} → {$verdict}");
        return $run;
    }

    // =========================================================================
    // Replay durability history
    // =========================================================================

    public function recordReplayDurability(
        string $tenantId,
        string $windowType,
        array  $metrics = []
    ): ReplayDurabilityHistory {
        if (!in_array($windowType, self::WINDOW_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid window type: {$windowType}");
        }

        $successRate  = $metrics['replay_success_rate_pct']      ?? 1.0;
        $avgLatency   = $metrics['avg_recovery_latency_seconds']  ?? 0.0;
        $ampAvg       = $metrics['replay_amplification_avg']      ?? 1.0;
        $total        = $metrics['total_recovery_events']         ?? 0;
        $failed       = $metrics['failed_recovery_events']        ?? 0;
        $backlogSlope = $metrics['backlog_trend_slope']           ?? 0.0;

        $acceptable   = $successRate  >= self::MIN_CONTINUITY_PCT
                     && $ampAvg       <= self::MAX_REPLAY_AMPLIFICATION
                     && $backlogSlope <= 0.01;

        return ReplayDurabilityHistory::create([
            'history_id'                   => 'rdh-' . Str::uuid(),
            'tenant_id'                    => $tenantId,
            'window_type'                  => $windowType,
            'replay_success_rate_pct'      => $successRate,
            'avg_recovery_latency_seconds' => $avgLatency,
            'replay_amplification_avg'     => $ampAvg,
            'total_recovery_events'        => $total,
            'failed_recovery_events'       => $failed,
            'backlog_trend_slope'          => $backlogSlope,
            'durability_acceptable'        => $acceptable,
            'is_advisory'                  => true,
            'durability_evidence'          => $metrics ?: null,
        ]);
    }

    // =========================================================================
    // Infrastructure stability
    // =========================================================================

    public function recordInfrastructureStability(
        string $tenantId,
        string $windowType,
        array  $metrics = []
    ): InfrastructureStabilityReport {
        if (!in_array($windowType, self::WINDOW_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid window type: {$windowType}");
        }

        $cpuSlope     = $metrics['cpu_trend_slope']     ?? 0.0;
        $memSlope     = $metrics['memory_trend_slope']  ?? 0.0;
        $storageSlope = $metrics['storage_trend_slope'] ?? 0.0;

        $verdict = match(true) {
            $cpuSlope > 0.05 || $storageSlope > 0.05 => 'critical',
            $cpuSlope > 0.02 || $storageSlope > 0.02 => 'degrading',
            $cpuSlope > 0.01 || $storageSlope > 0.01 => 'monitoring',
            default                                   => 'stable',
        };

        return InfrastructureStabilityReport::create([
            'report_id'               => 'isr-' . Str::uuid(),
            'tenant_id'               => $tenantId,
            'window_type'             => $windowType,
            'avg_cpu_pct'             => $metrics['avg_cpu_pct']              ?? 0.0,
            'avg_memory_growth_mb'    => $metrics['avg_memory_growth_mb']    ?? 0.0,
            'avg_storage_pressure_pct'=> $metrics['avg_storage_pressure_pct'] ?? 0.0,
            'avg_query_latency_ms'    => $metrics['avg_query_latency_ms']    ?? 0.0,
            'cpu_trend_slope'         => $cpuSlope,
            'memory_trend_slope'      => $memSlope,
            'storage_trend_slope'     => $storageSlope,
            'stability_verdict'       => $verdict,
            'is_advisory'             => true,
            'stability_evidence'      => $metrics ?: null,
        ]);
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'total_windows'          => OperationalValidationWindow::count(),
            'criteria_met'           => OperationalValidationWindow::where('criteria_met', true)->count(),
            'trend_critical'         => TelemetryTrendReport::where('trend_verdict', 'critical')->count(),
            'drift_critical'         => OperationalDriftHistory::where('drift_verdict', 'critical')->count(),
            'governance_pass'        => GovernanceReportingRun::where('governance_verdict', 'pass')->count(),
            'governance_fail'        => GovernanceReportingRun::where('governance_verdict', 'fail')->count(),
            'replay_acceptable'      => ReplayDurabilityHistory::where('durability_acceptable', true)->count(),
            'infra_stable'           => InfrastructureStabilityReport::where('stability_verdict', 'stable')->count(),
            'fp_worsening'           => FalsePositiveEvolutionReport::where('fp_verdict', 'worsening')->orWhere('fp_verdict', 'critical')->count(),
        ];
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function windowDays(string $windowType): int
    {
        return match($windowType) {
            '7d'  => 7,
            '14d' => 14,
            '30d' => 30,
            default => 7,
        };
    }

    private function writeAudit(
        string $tenantId,
        string $eventType,
        string $actor,
        string $outcome,
        string $description
    ): void {
        ProductionGovernanceAudit::create([
            'audit_id'    => 'pga-' . Str::uuid(),
            'tenant_id'   => $tenantId,
            'event_type'  => $eventType,
            'actor'       => $actor,
            'outcome'     => $outcome,
            'description' => $description,
            'is_advisory' => true,
        ]);
    }
}
