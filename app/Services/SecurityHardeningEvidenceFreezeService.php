<?php

namespace App\Services;

use App\Models\SecurityHardeningFreezeRun;
use App\Models\SecurityHardeningFreezeCheck;
use App\Models\SecurityHardeningFreezeControlEvidence;
use App\Models\SecurityHardeningFreezeGateSnapshot;
use App\Models\SecurityHardeningFreezeCoverageReport;
use App\Models\SecurityHardeningFreezeRemediationGuidance;
use App\Models\SecurityHardeningFreezeCertificationRequest;
use App\Models\SecurityHardeningFreezeAuditEvent;
use App\Models\SecurityHardeningFreezeDeltaReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-074: Consolidated Security Hardening Evidence Freeze.
 *
 * Advisory-only. Records evidence of all security hardening controls
 * implemented in this iteration (ENV-CACHE-DRIFT, CMD-SHARED-HMAC,
 * AGENT-TENANCY-GAP, TENANT-UNSCOPED-TABLES, RATE-LIMIT-BYPASS,
 * ENTERPRISE-068–073). No autonomous actions, no enforcement changes.
 */
class SecurityHardeningEvidenceFreezeService
{
    public const ADVISORY_ONLY          = true;
    public const SELF_APPROVE_BLOCKED   = true;
    public const FREEZE_VERSION         = 'v1';
    public const MIN_PASS_SCORE         = 0.85;
    public const MAX_CONTROLS           = 50;

    public const CONTROL_IDS = [
        'config_cache_auth_secret',
        'internal_auth_secret_mapped',
        'per_agent_hmac_secret',
        'endpoint_fleet_tenant_isolation',
        'workflow_tables_tenant_isolation',
        'threat_hunts_append_only_isolated',
        'ingestion_tenant_header_validation',
        'rls_scaffold_present',
        'container_resource_limits',
        'tenant_strict_mode_configured',
    ];

    public const CONTROL_CATEGORIES = [
        'config_cache_auth_secret'          => 'config_security',
        'internal_auth_secret_mapped'       => 'config_security',
        'per_agent_hmac_secret'             => 'endpoint_security',
        'endpoint_fleet_tenant_isolation'   => 'tenant_isolation',
        'workflow_tables_tenant_isolation'  => 'tenant_isolation',
        'threat_hunts_append_only_isolated' => 'tenant_isolation',
        'ingestion_tenant_header_validation'=> 'ingestion_security',
        'rls_scaffold_present'              => 'infrastructure',
        'container_resource_limits'         => 'infrastructure',
        'tenant_strict_mode_configured'     => 'config_security',
    ];

    // =========================================================================
    // Freeze run lifecycle
    // =========================================================================

    public function runFreeze(string $operatorId = 'system', array $params = []): SecurityHardeningFreezeRun
    {
        $run = SecurityHardeningFreezeRun::create([
            'run_id'                   => 'shf-' . Str::uuid(),
            'freeze_version'           => self::FREEZE_VERSION,
            'operator_id'              => $operatorId,
            'run_state'                => 'running',
            'controls_total'           => count(self::CONTROL_IDS),
            'advisory_only'            => true,
            'autonomous_certification' => false,
            'self_approve_blocked'     => true,
            'run_metadata'             => $params,
        ]);

        $this->recordAudit('freeze_run_started', $operatorId, $run->run_id);

        return $run;
    }

    public function completeRun(SecurityHardeningFreezeRun $run, int $passed, int $failed): SecurityHardeningFreezeRun
    {
        $total = $passed + $failed;
        $score = $total > 0 ? round($passed / $total, 4) : 0.0;
        $score = min(1.0, max(0.0, $score));

        $run->update([
            'run_state'       => 'completed',
            'controls_passed' => $passed,
            'controls_failed' => $failed,
            'coverage_score'  => $score,
            'completed_at'    => now()->format('Y-m-d H:i:sP'),
        ]);

        $this->recordAudit('freeze_run_completed', $run->operator_id ?? 'system', $run->run_id);

        return $run->fresh();
    }

    // =========================================================================
    // Control evaluation
    // =========================================================================

    public function evaluateControl(string $controlId, SecurityHardeningFreezeRun $run): SecurityHardeningFreezeCheck
    {
        if (!in_array($controlId, self::CONTROL_IDS, true)) {
            throw new \InvalidArgumentException("Unknown control: {$controlId}");
        }

        $result = $this->dispatchControl($controlId);
        $passed = ($result['result'] ?? 'FAIL') === 'PASS';

        $check = SecurityHardeningFreezeCheck::create([
            'check_id'         => 'chk-' . Str::uuid(),
            'run_id'           => $run->run_id,
            'control_id'       => $controlId,
            'control_category' => self::CONTROL_CATEGORIES[$controlId] ?? 'unknown',
            'result'           => $passed ? 'PASS' : 'FAIL',
            'passed'           => $passed,
            'detail'           => $result['detail'] ?? '',
            'advisory_only'    => true,
            'check_metadata'   => $result['metadata'] ?? null,
            'evaluated_at'     => now()->format('Y-m-d H:i:sP'),
        ]);

        if (!$passed) {
            $this->addRemediationGuidance($controlId, $run->run_id, $result['guidance'] ?? '');
        }

        return $check;
    }

    public function evaluateAllControls(SecurityHardeningFreezeRun $run): array
    {
        $checks  = [];
        $passed  = 0;
        $failed  = 0;

        foreach (self::CONTROL_IDS as $controlId) {
            $check = $this->evaluateControl($controlId, $run);
            $checks[] = $check;
            $check->passed ? $passed++ : $failed++;
        }

        $this->completeRun($run, $passed, $failed);
        $this->computeCoverage($run->fresh());

        return $checks;
    }

    // =========================================================================
    // Coverage report
    // =========================================================================

    public function computeCoverage(SecurityHardeningFreezeRun $run): SecurityHardeningFreezeCoverageReport
    {
        $total   = $run->controls_total ?: count(self::CONTROL_IDS);
        $passing = $run->controls_passed;
        $failing = $run->controls_failed;
        $score   = $total > 0 ? round($passing / $total, 4) : 0.0;
        $score   = min(1.0, max(0.0, $score));

        $byCategory = [];
        foreach (array_unique(array_values(self::CONTROL_CATEGORIES)) as $cat) {
            $catControls = array_keys(array_filter(
                self::CONTROL_CATEGORIES,
                fn ($c) => $c === $cat
            ));
            $catPassing = SecurityHardeningFreezeCheck::where('run_id', $run->run_id)
                ->whereIn('control_id', $catControls)
                ->where('passed', true)
                ->count();
            $catTotal = count($catControls);
            $byCategory[$cat] = [
                'passing' => $catPassing,
                'total'   => $catTotal,
                'score'   => $catTotal > 0 ? round($catPassing / $catTotal, 4) : 0.0,
            ];
        }

        return SecurityHardeningFreezeCoverageReport::create([
            'report_id'            => 'cov-' . Str::uuid(),
            'run_id'               => $run->run_id,
            'overall_score'        => $score,
            'total_controls'       => $total,
            'passing_controls'     => $passing,
            'failing_controls'     => $failing,
            'meets_pass_threshold' => $score >= self::MIN_PASS_SCORE,
            'advisory_only'        => true,
            'per_category_scores'  => $byCategory,
        ]);
    }

    // =========================================================================
    // Certification request (advisory only — never autonomous)
    // =========================================================================

    public function requestCertification(
        SecurityHardeningFreezeRun $run,
        string $requestedBy,
        ?string $approvedBy = null
    ): SecurityHardeningFreezeCertificationRequest {
        // Self-approve is never permitted
        if ($approvedBy !== null && $approvedBy === $requestedBy) {
            throw new \LogicException('Self-approval is not permitted (SELF_APPROVE_BLOCKED).');
        }

        $request = SecurityHardeningFreezeCertificationRequest::create([
            'request_id'           => 'cert-req-' . Str::uuid(),
            'run_id'               => $run->run_id,
            'requested_by'         => $requestedBy,
            'request_state'        => 'pending',
            'self_approve_blocked' => true,
            'autonomous_approval'  => false,
            'advisory_only'        => true,
            'justification'        => "Security hardening freeze run {$run->run_id} — analyst review required.",
        ]);

        $this->recordAudit('certification_requested', $requestedBy, $run->run_id);

        return $request;
    }

    // =========================================================================
    // Delta report
    // =========================================================================

    public function computeDelta(
        SecurityHardeningFreezeRun $current,
        ?SecurityHardeningFreezeRun $previous
    ): SecurityHardeningFreezeDeltaReport {
        $prevScore    = $previous?->coverage_score ?? 0.0;
        $scoreDelta   = round(($current->coverage_score ?? 0.0) - $prevScore, 4);
        $regression   = $scoreDelta < 0;

        return SecurityHardeningFreezeDeltaReport::create([
            'delta_id'           => 'delta-' . Str::uuid(),
            'current_run_id'     => $current->run_id,
            'previous_run_id'    => $previous?->run_id,
            'controls_added'     => 0,
            'controls_removed'   => 0,
            'controls_regressed' => $regression ? abs((int) round($scoreDelta * count(self::CONTROL_IDS))) : 0,
            'controls_improved'  => !$regression ? (int) round($scoreDelta * count(self::CONTROL_IDS)) : 0,
            'score_delta'        => $scoreDelta,
            'regression_detected'=> $regression,
        ]);
    }

    // =========================================================================
    // Status
    // =========================================================================

    public function getStatus(SecurityHardeningFreezeRun $run): array
    {
        $coverage = SecurityHardeningFreezeCoverageReport::where('run_id', $run->run_id)
            ->latest()
            ->first();

        return [
            'run_id'           => $run->run_id,
            'run_state'        => $run->run_state,
            'freeze_version'   => $run->freeze_version,
            'advisory_only'    => true,
            'coverage_score'   => $coverage?->overall_score ?? $run->coverage_score,
            'controls_total'   => $run->controls_total,
            'controls_passed'  => $run->controls_passed,
            'controls_failed'  => $run->controls_failed,
            'meets_threshold'  => ($coverage?->overall_score ?? 0) >= self::MIN_PASS_SCORE,
            'note'             => 'Advisory only — evidence freeze never modifies security controls.',
        ];
    }

    // =========================================================================
    // Audit
    // =========================================================================

    public function recordAudit(string $eventType, string $actor = 'system', ?string $runId = null): SecurityHardeningFreezeAuditEvent
    {
        return SecurityHardeningFreezeAuditEvent::create([
            'event_id'    => 'audit-' . Str::uuid(),
            'run_id'      => $runId,
            'event_type'  => $eventType,
            'actor'       => $actor,
            'occurred_at' => now()->format('Y-m-d H:i:sP'),
        ]);
    }

    // =========================================================================
    // Private: control evaluators
    // =========================================================================

    private function dispatchControl(string $controlId): array
    {
        return match ($controlId) {
            'config_cache_auth_secret'          => $this->evalConfigCacheAuthSecret(),
            'internal_auth_secret_mapped'       => $this->evalInternalAuthSecretMapped(),
            'per_agent_hmac_secret'             => $this->evalPerAgentHmacSecret(),
            'endpoint_fleet_tenant_isolation'   => $this->evalEndpointFleetTenantIsolation(),
            'workflow_tables_tenant_isolation'  => $this->evalWorkflowTablesTenantIsolation(),
            'threat_hunts_append_only_isolated' => $this->evalThreatHuntsAppendOnlyIsolated(),
            'ingestion_tenant_header_validation'=> $this->evalIngestionTenantHeaderValidation(),
            'rls_scaffold_present'              => $this->evalRlsScaffoldPresent(),
            'container_resource_limits'         => $this->evalContainerResourceLimits(),
            'tenant_strict_mode_configured'     => $this->evalTenantStrictModeConfigured(),
            default                             => ['result' => 'FAIL', 'detail' => "Unknown control: {$controlId}"],
        };
    }

    private function evalConfigCacheAuthSecret(): array
    {
        $content = @file_get_contents(app_path('Services/InternalAuthService.php')) ?? '';
        $usesConfig = str_contains($content, "config('xdr.internal_auth_secret'");
        $noRawEnv   = !str_contains($content, "env('XDR_INTERNAL_AUTH_SECRET'");
        $passed     = $usesConfig && $noRawEnv;

        return [
            'result'   => $passed ? 'PASS' : 'FAIL',
            'detail'   => $passed
                ? 'InternalAuthService uses config() — config:cache bypass prevented.'
                : 'InternalAuthService still uses env() — config:cache bypass possible.',
            'guidance' => 'Replace env(\'XDR_INTERNAL_AUTH_SECRET\') with config(\'xdr.internal_auth_secret\') in InternalAuthService.php.',
            'metadata' => ['uses_config' => $usesConfig, 'no_raw_env' => $noRawEnv],
        ];
    }

    private function evalInternalAuthSecretMapped(): array
    {
        $content = @file_get_contents(config_path('xdr.php')) ?? '';
        $mapped  = str_contains($content, "'internal_auth_secret'");

        return [
            'result'   => $mapped ? 'PASS' : 'FAIL',
            'detail'   => $mapped
                ? 'xdr.internal_auth_secret key present in config/xdr.php.'
                : 'xdr.internal_auth_secret not mapped in config/xdr.php.',
            'guidance' => "Add 'internal_auth_secret' => env('XDR_INTERNAL_AUTH_SECRET', '') to config/xdr.php.",
            'metadata' => ['key_present' => $mapped],
        ];
    }

    private function evalPerAgentHmacSecret(): array
    {
        $hasColumn = Schema::hasColumn('endpoint_agents', 'hmac_secret');

        return [
            'result'   => $hasColumn ? 'PASS' : 'FAIL',
            'detail'   => $hasColumn
                ? 'endpoint_agents.hmac_secret column present — per-agent HMAC secret enforced.'
                : 'endpoint_agents.hmac_secret column missing.',
            'guidance' => 'Run migration 2026_06_29_070001_add_hmac_secret_and_tenant_id_to_endpoint_agents.',
            'metadata' => ['column_exists' => $hasColumn],
        ];
    }

    private function evalEndpointFleetTenantIsolation(): array
    {
        $inIsolated = in_array('endpoint_agents', \App\Services\TenantBoundaryService::ISOLATED_TABLES, true);
        $notUnisolated = !in_array('endpoint_agents', \App\Services\TenantBoundaryService::UNISOLATED_TABLES, true);
        $hasColumn  = Schema::hasColumn('endpoint_agents', 'tenant_id');
        $passed     = $inIsolated && $notUnisolated && $hasColumn;

        return [
            'result'   => $passed ? 'PASS' : 'FAIL',
            'detail'   => $passed
                ? 'endpoint_agents isolated: in ISOLATED_TABLES, tenant_id column present.'
                : 'endpoint_agents not fully isolated.',
            'guidance' => 'Add endpoint_agents to ISOLATED_TABLES, remove from UNISOLATED_TABLES, add tenant_id column.',
            'metadata' => [
                'in_isolated_tables'  => $inIsolated,
                'not_unisolated'      => $notUnisolated,
                'tenant_id_column'    => $hasColumn,
            ],
        ];
    }

    private function evalWorkflowTablesTenantIsolation(): array
    {
        $tables   = ['investigations', 'response_plans', 'entities'];
        $isolated = \App\Services\TenantBoundaryService::ISOLATED_TABLES;
        $missing  = [];

        foreach ($tables as $tbl) {
            $inIsolated  = in_array($tbl, $isolated, true);
            $hasCol      = Schema::hasColumn($tbl, 'tenant_id');
            if (!$inIsolated || !$hasCol) {
                $missing[] = $tbl;
            }
        }

        $passed = empty($missing);

        return [
            'result'   => $passed ? 'PASS' : 'FAIL',
            'detail'   => $passed
                ? 'Workflow tables (investigations, response_plans, entities) have tenant_id and are isolated.'
                : 'Workflow tables missing isolation: ' . implode(', ', $missing),
            'guidance' => 'Run migration 2026_06_29_080001_add_tenant_id_to_unscoped_tables.',
            'metadata' => ['missing_tables' => $missing],
        ];
    }

    private function evalThreatHuntsAppendOnlyIsolated(): array
    {
        $inAppendOnly  = in_array('threat_hunts', \App\Services\TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES, true);
        $notMutable    = !in_array('threat_hunts', \App\Services\TenantBoundaryService::MUTABLE_TABLES, true);
        $hasColumn     = Schema::hasColumn('threat_hunts', 'tenant_id');
        $passed        = $inAppendOnly && $notMutable && $hasColumn;

        return [
            'result'   => $passed ? 'PASS' : 'FAIL',
            'detail'   => $passed
                ? 'threat_hunts in APPEND_ONLY_ISOLATED_TABLES with tenant_id column.'
                : 'threat_hunts isolation gap detected.',
            'guidance' => 'Add threat_hunts to APPEND_ONLY_ISOLATED_TABLES (not MUTABLE_TABLES); add tenant_id column.',
            'metadata' => [
                'in_append_only'   => $inAppendOnly,
                'not_in_mutable'   => $notMutable,
                'tenant_id_column' => $hasColumn,
            ],
        ];
    }

    private function evalIngestionTenantHeaderValidation(): array
    {
        $mainGo  = base_path('services/ingestion-gateway/main.go');
        $content = @file_get_contents($mainGo) ?? '';
        $hasHelper  = str_contains($content, 'extractPayloadTenantID');
        $hasReject  = str_contains($content, 'tenant_id_header_mismatch');
        $passed     = $hasHelper && $hasReject;

        return [
            'result'   => $passed ? 'PASS' : 'FAIL',
            'detail'   => $passed
                ? 'ingestion-gateway validates X-Tenant-ID vs payload tenant_id.'
                : 'ingestion-gateway missing tenant_id header validation.',
            'guidance' => 'Add extractPayloadTenantID() and header mismatch rejection in main.go.',
            'metadata' => ['has_helper' => $hasHelper, 'has_reject' => $hasReject],
        ];
    }

    private function evalRlsScaffoldPresent(): array
    {
        $migrations = glob(database_path('migrations/*scaffold_rls_policies*')) ?: [];
        $exists     = !empty($migrations);

        return [
            'result'   => $exists ? 'PASS' : 'FAIL',
            'detail'   => $exists
                ? 'RLS scaffold migration present (advisory — not enforced).'
                : 'RLS scaffold migration missing.',
            'guidance' => 'Run ENTERPRISE-069 to create the scaffold_rls_policies migration.',
            'metadata' => ['migration_count' => count($migrations)],
        ];
    }

    private function evalContainerResourceLimits(): array
    {
        $compose = @file_get_contents(base_path('docker-compose.yml')) ?? '';
        $hasLimits = str_contains($compose, 'resources:') && str_contains($compose, 'limits:');

        return [
            'result'   => $hasLimits ? 'PASS' : 'FAIL',
            'detail'   => $hasLimits
                ? 'docker-compose.yml has deploy.resources.limits.'
                : 'docker-compose.yml missing resource limits.',
            'guidance' => 'Run ENTERPRISE-068 to add deploy.resources.limits to docker-compose.yml.',
            'metadata' => ['limits_present' => $hasLimits],
        ];
    }

    private function evalTenantStrictModeConfigured(): array
    {
        $strictModeKey = config('xdr.tenancy.strict_mode');
        $keyExists     = array_key_exists('tenancy', config('xdr', []));

        return [
            'result'   => $keyExists ? 'PASS' : 'FAIL',
            'detail'   => $keyExists
                ? 'xdr.tenancy.strict_mode config key present (currently: ' . ($strictModeKey ? 'true' : 'false') . ').'
                : 'xdr.tenancy section missing from config/xdr.php.',
            'guidance' => "Add 'tenancy' section to config/xdr.php with strict_mode key.",
            'metadata' => ['key_exists' => $keyExists, 'current_value' => (bool) $strictModeKey],
        ];
    }

    private function addRemediationGuidance(string $controlId, string $runId, string $text): void
    {
        if ($text === '') {
            return;
        }
        SecurityHardeningFreezeRemediationGuidance::create([
            'guidance_id'            => 'guid-' . Str::uuid(),
            'run_id'                 => $runId,
            'control_id'             => $controlId,
            'priority'               => 'medium',
            'guidance_text'          => $text,
            'autonomous_remediation' => false,
        ]);
    }
}
