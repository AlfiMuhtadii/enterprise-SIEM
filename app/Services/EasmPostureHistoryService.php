<?php

namespace App\Services;

use App\Models\EasmAssetRiskScore;
use App\Models\EasmFinding;
use App\Models\EasmFindingChange;
use App\Models\EasmPostureSnapshot;
use App\Models\EasmScanRun;
use App\Models\WebsiteAsset;

/**
 * EasmPostureHistoryService — posture score, finding diff, risk trend.
 *
 * ADVISORY_ONLY = true. All output is advisory and never creates SecurityIncident
 * records or modifies detection rules / ACTIVE_ALLOWLIST.
 */
class EasmPostureHistoryService
{
    public const ADVISORY_ONLY = true;

    public const SEVERITY_DEDUCTIONS = [
        'high'   => 20,
        'medium' => 10,
        'low'    => 3,
        'info'   => 1,
    ];

    public const SEVERITY_ORDER = ['info', 'low', 'medium', 'high'];

    // =========================================================================
    // Scoring
    // =========================================================================

    /**
     * Compute posture score 0–100 from a list of normalised findings.
     * Each finding deducts points by severity; score never goes below 0.
     */
    public function computePostureScore(array $findings): int
    {
        $deduction = 0;
        foreach ($findings as $finding) {
            $sev = $finding['severity'] ?? 'info';
            $deduction += self::SEVERITY_DEDUCTIONS[$sev] ?? 0;
        }
        return max(0, 100 - $deduction);
    }

    /**
     * Classify risk tier from posture score.
     *
     * 80–100 → low | 60–79 → medium | 40–59 → high | 0–39 → critical
     */
    public function classifyRiskTier(int $score): string
    {
        return match (true) {
            $score >= 80 => 'low',
            $score >= 60 => 'medium',
            $score >= 40 => 'high',
            default      => 'critical',
        };
    }

    /**
     * Determine posture trend direction.
     *
     * improving | degrading | stable.
     * Null previous (first scan) → stable.
     */
    public function determineTrendDirection(int $currentScore, ?int $previousScore): string
    {
        if ($previousScore === null) {
            return 'stable';
        }
        if ($currentScore > $previousScore) {
            return 'improving';
        }
        if ($currentScore < $previousScore) {
            return 'degrading';
        }
        return 'stable';
    }

    // =========================================================================
    // Finding diff
    // =========================================================================

    /**
     * Compute finding-level diff between two scan snapshots.
     *
     * Each element in $previousFindings / $currentFindings must have
     * 'finding_key' and 'severity' keys.
     *
     * Returns array of:
     *   ['finding_key', 'change_type' (new|resolved|unchanged|regressed),
     *    'severity_before' (null for new), 'severity_after' (null for resolved)]
     */
    public function diffFindings(array $previousFindings, array $currentFindings): array
    {
        $severityRank = array_flip(self::SEVERITY_ORDER); // info=0, low=1, medium=2, high=3

        $prevByKey = [];
        foreach ($previousFindings as $f) {
            $prevByKey[$f['finding_key']] = $f;
        }

        $currByKey = [];
        foreach ($currentFindings as $f) {
            $currByKey[$f['finding_key']] = $f;
        }

        $diffs = [];

        foreach ($currByKey as $key => $curr) {
            $currSev = $curr['severity'] ?? 'info';

            if (!isset($prevByKey[$key])) {
                $diffs[] = [
                    'finding_key'     => $key,
                    'change_type'     => 'new',
                    'severity_before' => null,
                    'severity_after'  => $currSev,
                ];
            } else {
                $prevSev  = $prevByKey[$key]['severity'] ?? 'info';
                $prevRank = $severityRank[$prevSev] ?? 0;
                $currRank = $severityRank[$currSev] ?? 0;

                $diffs[] = [
                    'finding_key'     => $key,
                    'change_type'     => $currRank > $prevRank ? 'regressed' : 'unchanged',
                    'severity_before' => $prevSev,
                    'severity_after'  => $currSev,
                ];
            }
        }

        foreach ($prevByKey as $key => $prev) {
            if (!isset($currByKey[$key])) {
                $diffs[] = [
                    'finding_key'     => $key,
                    'change_type'     => 'resolved',
                    'severity_before' => $prev['severity'] ?? 'info',
                    'severity_after'  => null,
                ];
            }
        }

        return $diffs;
    }

    // =========================================================================
    // Persistence
    // =========================================================================

    /**
     * Create an append-only posture snapshot for a completed scan run.
     */
    public function createPostureSnapshot(
        EasmScanRun   $run,
        WebsiteAsset  $asset,
        array         $findings,
        int           $score,
        array         $diffs
    ): EasmPostureSnapshot {
        $bySev = ['high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
        foreach ($findings as $f) {
            $sev = $f['severity'] ?? 'info';
            if (isset($bySev[$sev])) {
                $bySev[$sev]++;
            }
        }

        $byChange = ['new' => 0, 'resolved' => 0, 'regressed' => 0, 'unchanged' => 0];
        foreach ($diffs as $d) {
            $ct = $d['change_type'] ?? 'unchanged';
            if (isset($byChange[$ct])) {
                $byChange[$ct]++;
            }
        }

        $overallStatus = 'PASS';
        if ($bySev['high'] > 0 || $bySev['medium'] > 0) {
            $overallStatus = 'FINDINGS';
        } elseif (count($findings) > 0) {
            $overallStatus = 'WARN';
        }

        return EasmPostureSnapshot::create([
            'tenant_id'            => $asset->tenant_id,
            'asset_id'             => $asset->id,
            'run_id'               => $run->run_id,
            'posture_score'        => $score,
            'risk_tier'            => $this->classifyRiskTier($score),
            'finding_count_total'  => count($findings),
            'finding_count_high'   => $bySev['high'],
            'finding_count_medium' => $bySev['medium'],
            'finding_count_low'    => $bySev['low'],
            'finding_count_info'   => $bySev['info'],
            'new_findings'         => $byChange['new'],
            'resolved_findings'    => $byChange['resolved'],
            'regressed_findings'   => $byChange['regressed'],
            'unchanged_findings'   => $byChange['unchanged'],
            'overall_status'       => $overallStatus,
            'snapshot_at'          => now()->format('Y-m-d H:i:sP'),
        ]);
    }

    /**
     * Record per-finding change events for a scan run (append-only).
     * Returns the created EasmFindingChange records.
     */
    public function recordFindingChanges(EasmScanRun $run, WebsiteAsset $asset, array $diffs): array
    {
        $records = [];
        $now     = now()->format('Y-m-d H:i:sP');

        foreach ($diffs as $diff) {
            $records[] = EasmFindingChange::create([
                'tenant_id'       => $asset->tenant_id,
                'asset_id'        => $asset->id,
                'run_id'          => $run->run_id,
                'finding_key'     => $diff['finding_key'],
                'change_type'     => $diff['change_type'],
                'severity_before' => $diff['severity_before'],
                'severity_after'  => $diff['severity_after'],
                'changed_at'      => $now,
            ]);
        }

        return $records;
    }

    /**
     * Upsert the current risk score for an asset.
     *
     * On first scan: $previousScore may be null.
     * On update: previous score is derived from the stored value, not from parameter.
     */
    public function upsertAssetRiskScore(WebsiteAsset $asset, int $currentScore, ?int $previousScore): EasmAssetRiskScore
    {
        $existing = EasmAssetRiskScore::where('tenant_id', $asset->tenant_id)
            ->where('asset_id', $asset->id)
            ->first();

        if ($existing === null) {
            $trend = $this->determineTrendDirection($currentScore, $previousScore);
            return EasmAssetRiskScore::create([
                'tenant_id'        => $asset->tenant_id,
                'asset_id'         => $asset->id,
                'risk_score'       => $currentScore,
                'risk_tier'        => $this->classifyRiskTier($currentScore),
                'trend_direction'  => $trend,
                'previous_score'   => $previousScore,
                'total_scans'      => 1,
                'last_computed_at' => now()->format('Y-m-d H:i:sP'),
            ]);
        }

        $oldScore                   = $existing->risk_score;
        $existing->previous_score   = $oldScore;
        $existing->risk_score       = $currentScore;
        $existing->risk_tier        = $this->classifyRiskTier($currentScore);
        $existing->trend_direction  = $this->determineTrendDirection($currentScore, $oldScore);
        $existing->total_scans      = ($existing->total_scans ?? 0) + 1;
        $existing->last_computed_at = now()->format('Y-m-d H:i:sP');
        $existing->save();

        return $existing;
    }

    // =========================================================================
    // Reports
    // =========================================================================

    /**
     * Generate a posture trend report for an asset: last $limit snapshots.
     */
    public function generateTrendReport(WebsiteAsset $asset, int $limit = 10): array
    {
        $snapshots = EasmPostureSnapshot::where('asset_id', $asset->id)
            ->where('tenant_id', $asset->tenant_id)
            ->orderByDesc('snapshot_at')
            ->limit($limit)
            ->get()
            ->values();

        $riskScore = EasmAssetRiskScore::where('asset_id', $asset->id)
            ->where('tenant_id', $asset->tenant_id)
            ->first();

        return [
            'asset' => [
                'id'       => $asset->id,
                'name'     => $asset->name,
                'url'      => $asset->url,
                'hostname' => $asset->hostname,
            ],
            'tenant_id'     => $asset->tenant_id,
            'is_advisory'   => true,
            'current_score' => $riskScore?->risk_score,
            'risk_tier'     => $riskScore?->risk_tier,
            'trend'         => $riskScore?->trend_direction ?? 'stable',
            'total_scans'   => $riskScore?->total_scans ?? 0,
            'snapshots'     => $snapshots->map(fn ($s) => [
                'score'              => $s->posture_score,
                'risk_tier'          => $s->risk_tier,
                'finding_count'      => $s->finding_count_total,
                'new_findings'       => $s->new_findings,
                'resolved_findings'  => $s->resolved_findings,
                'regressed_findings' => $s->regressed_findings,
                'overall_status'     => $s->overall_status,
                'snapshot_at'        => $s->snapshot_at?->toIso8601String(),
            ])->toArray(),
        ];
    }

    /**
     * Generate a tenant-level EASM summary across all registered assets.
     */
    public function getTenantEasmSummary(string $tenantId): array
    {
        $assets       = WebsiteAsset::where('tenant_id', $tenantId)->get();
        $totalFindings = EasmFinding::where('tenant_id', $tenantId)->count();
        $openFindings  = EasmFinding::where('tenant_id', $tenantId)->where('status', 'open')->count();

        $bySeverity = [];
        foreach (['high', 'medium', 'low', 'info'] as $sev) {
            $bySeverity[$sev] = EasmFinding::where('tenant_id', $tenantId)
                ->where('severity', $sev)
                ->where('status', 'open')
                ->count();
        }

        // Lowest score = highest risk
        $highestRisk = EasmAssetRiskScore::where('tenant_id', $tenantId)
            ->orderBy('risk_score')
            ->first();

        return [
            'tenant_id'      => $tenantId,
            'is_advisory'    => true,
            'asset_count'    => $assets->count(),
            'total_findings' => $totalFindings,
            'open_findings'  => $openFindings,
            'by_severity'    => $bySeverity,
            'highest_risk_asset' => $highestRisk ? [
                'asset_id'  => $highestRisk->asset_id,
                'risk_score' => $highestRisk->risk_score,
                'risk_tier'  => $highestRisk->risk_tier,
                'trend'     => $highestRisk->trend_direction,
            ] : null,
        ];
    }
}
