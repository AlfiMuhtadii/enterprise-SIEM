<?php

namespace App\Services;

use App\Models\SoakPlanRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-060: Real Domain Soak Execution Plan
 *
 * Plans checks and records advisory diagnostic readiness. This service
 * never schedules a soak or treats fixture/rehearsal evidence as a live result.
 *
 * ADVISORY_ONLY = true.
 * REAL_EXECUTION_GATED = true — each phase requires explicit operator trigger.
 * Promotion remains BLOCKED for endpoint/network/threat-intel until real 6h soak PASS.
 */
class RealDomainSoakPlanService
{
    public const ADVISORY_ONLY        = true;
    public const REAL_EXECUTION_GATED = true;
    public const PHASES_TOTAL         = 4;

    private const READINESS_POLICY = 'diagnostics-v1';
    private const DIAGNOSTIC_READY = 'DIAGNOSTIC_READY';

    public function __construct(private readonly DomainSoakHarnessService $harness) {}

    private const PHASE_DEFINITIONS = [
        1 => [
            'name'         => 'Staged-Active Baseline Diagnostics',
            'rule_scope'   => 'staged_active (identity/cloud/saas — 12 empirical rules)',
            'rule_count'   => 12,
            'purpose'      => 'Check the active correlation baseline. Fixture inventory is not a live result.',
            'diagnostic_command' => 'php artisan xdr:strangler-status',
        ],
        2 => [
            'name'         => 'Top Shadow-Ready Rules — Real Soak Evidence',
            'rule_scope'   => 'endpoint, network, and UEBA advisory sources',
            'rule_count'   => 12,
            'purpose'      => 'Check domain prerequisites; independent representative source evidence is required.',
            'diagnostic_command' => 'php artisan domain:soak-preflight endpoint',
        ],
        3 => [
            'name'         => 'Tier-1 Fixture-Backed Rules — Replay Validation',
            'rule_scope'   => 'tier_1_immediate (has_replay_fixture=true, confidence_source=empirical)',
            'rule_count'   => 12,
            'purpose'      => 'Review tier_1 fixture evidence separately from field telemetry.',
            'diagnostic_command' => 'php artisan rule:run-fixtures --tier=tier_1_immediate --dry-run',
        ],
        4 => [
            'name'         => 'Endpoint Tier-1 Next Batch',
            'rule_scope'   => 'endpoint shadow (tier_2_next_batch — count TBD from registry)',
            'rule_count'   => 0,
            'purpose'      => 'Evaluate the endpoint candidate batch only after the preceding diagnostic gates pass.',
            'diagnostic_command' => 'php artisan domain:soak-preflight endpoint',
        ],
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    public function buildPlan(bool $dryRun = false): array
    {
        $planId = (string) Str::uuid();
        $ts     = now()->format('Y-m-d H:i:sP');

        $phases = [];
        $gates  = [];
        $notes  = [];

        for ($phaseNum = 1; $phaseNum <= self::PHASES_TOTAL; $phaseNum++) {
            $phaseGates  = $this->evaluatePhaseGates($planId, $phaseNum, $ts, $phases);
            $gates       = array_merge($gates, $phaseGates);

            $passed  = count(array_filter($phaseGates, fn ($g) => $g['status'] === 'pass'));
            $failed  = count(array_filter($phaseGates, fn ($g) => $g['status'] === 'fail'));
            $total   = count($phaseGates);

            $readiness = match(true) {
                $failed > 0         => 'BLOCKED',
                $passed === $total  => self::DIAGNOSTIC_READY,
                default             => 'PARTIAL',
            };

            $def      = self::PHASE_DEFINITIONS[$phaseNum];
            $phases[] = [
                'plan_run_id'      => $planId,
                'phase_number'     => $phaseNum,
                'phase_name'       => $def['name'],
                'rule_scope'       => $def['rule_scope'],
                'rule_count'       => $def['rule_count'],
                'readiness_status' => $readiness,
                'gates_passed'     => $passed,
                'gates_total'      => $total,
                'promotion_gated'  => true,
                'is_advisory'      => true,
                'recorded_at'      => $ts,
            ];

            $notes[] = [
                'plan_run_id'  => $planId,
                'phase_number' => $phaseNum,
                'note_type'    => 'diagnostic_command',
                'note_text'    => $def['diagnostic_command'],
                'is_advisory'  => true,
                'recorded_at'  => $ts,
            ];
            $notes[] = [
                'plan_run_id'  => $planId,
                'phase_number' => $phaseNum,
                'note_type'    => 'purpose',
                'note_text'    => $def['purpose'],
                'is_advisory'  => true,
                'recorded_at'  => $ts,
            ];
        }

        $notes[] = [
            'plan_run_id' => $planId,
            'phase_number' => 2,
            'note_type' => 'readiness_policy',
            'note_text' => self::READINESS_POLICY,
            'is_advisory' => true,
            'recorded_at' => $ts,
        ];
        $phasesReady   = count(array_filter($phases, fn ($p) => $p['readiness_status'] === self::DIAGNOSTIC_READY));
        $phasesBlocked = count(array_filter($phases, fn ($p) => $p['readiness_status'] === 'BLOCKED'));
        $totalGates    = count($gates);
        $gatesPassed   = count(array_filter($gates, fn ($g) => $g['status'] === 'pass'));

        $plan = [
            'plan_run_id'        => $planId,
            'phases_total'       => self::PHASES_TOTAL,
            'phases_ready'       => $phasesReady,
            'phases_partial'     => self::PHASES_TOTAL - $phasesReady - $phasesBlocked,
            'phases_blocked'     => $phasesBlocked,
            'total_gates'        => $totalGates,
            'gates_passed'       => $gatesPassed,
            'overall_readiness'  => match (true) {
                $phasesBlocked > 0 => 'BLOCKED',
                $phasesReady === self::PHASES_TOTAL => self::DIAGNOSTIC_READY,
                default => 'PARTIAL',
            },
            'real_execution_gated' => true,
            'is_advisory'        => true,
            'created_at'         => $ts,
        ];

        if (!$dryRun) {
            $this->persist($plan, $phases, $gates, $notes, $ts);
        }

        return compact('plan', 'phases', 'gates', 'notes');
    }

    public function getLatestPlan(): ?array
    {
        if (!Schema::hasTable('soak_plan_runs')) {
            return null;
        }
        $run = SoakPlanRun::orderByDesc('created_at')->orderByDesc('id')->first();
        if (!$run) {
            return null;
        }
        $planId = $run->plan_run_id;
        $plan = $run->toArray();
        $phases = DB::table('soak_plan_phases')->where('plan_run_id', $planId)->orderBy('phase_number')->get();
        $notes = DB::table('soak_plan_readiness_notes')->where('plan_run_id', $planId)->get();
        $currentPolicy = $notes->contains(fn ($note) => $note->note_type === 'readiness_policy'
            && $note->note_text === self::READINESS_POLICY);
        if (!$currentPolicy) {
            // Preserve stored reports; never surface their old verdicts as current readiness.
            $plan['overall_readiness'] = 'UNVERIFIED';
            $phases = $phases->map(fn ($phase) => array_replace((array) $phase, ['readiness_status' => 'UNVERIFIED']));
            $notes = $notes->reject(fn ($note) => $note->note_type === 'soak_command')->values();
        }
        return [
            'plan'   => $plan,
            'phases' => $phases->all(),
            'gates'  => DB::table('soak_plan_gates')->where('plan_run_id', $planId)->get()->toArray(),
            'notes'  => $notes->all(),
        ];
    }

    public function getPhaseDefinitions(): array
    {
        return self::PHASE_DEFINITIONS;
    }

    // ── Gate evaluation per phase ─────────────────────────────────────────────

    private function evaluatePhaseGates(string $planId, int $phase, string $ts, array $priorPhases): array
    {
        return match($phase) {
            1 => $this->phase1Gates($planId, $ts),
            2 => $this->phase2Gates($planId, $ts),
            3 => $this->phase3Gates($planId, $ts),
            4 => $this->phase4Gates($planId, $ts, $priorPhases),
            default => [],
        };
    }

    private function phase1Gates(string $planId, string $ts): array
    {
        // P1-01: empirical rules ready in backlog
        $empirical = Schema::hasTable('rule_fixture_backlogs')
            ? DB::table('rule_fixture_backlogs')->where('confidence_source', 'empirical')->count()
            : 0;
        $g[] = $this->gate($planId, 1, 'SPG-P1-01', 'empirical rules in rule_fixture_backlogs >= 12',
            $empirical >= 12 ? 'pass' : 'warn',
            "empirical_count={$empirical} (run rule:run-fixtures + rule:refresh-confidence)", $ts);

        // P1-02: tier_1 fixture files on disk
        $dir   = base_path('tests/fixtures/detection/tier1_batch1');
        $count = is_dir($dir) ? count(glob($dir . '/*.json')) : 0;
        $g[] = $this->gate($planId, 1, 'SPG-P1-02', 'tier_1 fixture files on disk >= 12',
            $count >= 12 ? 'pass' : 'fail',
            "fixture_files={$count}", $ts);

        // P1-03: soak PS1 script exists
        $ps1 = base_path('scripts/run_xdr_correlation_soak_6h.ps1');
        $g[] = $this->gate($planId, 1, 'SPG-P1-03', 'run_xdr_correlation_soak_6h.ps1 exists',
            file_exists($ps1) ? 'pass' : 'warn',
            file_exists($ps1) ? 'script present' : 'script not found — create before soak', $ts);

        // P1-04: XDR_CORRELATION_ENGINE=go
        $engine = env('XDR_CORRELATION_ENGINE', 'legacy');
        $g[] = $this->gate($planId, 1, 'SPG-P1-04', 'XDR_CORRELATION_ENGINE=go (active correlation)',
            $engine === 'go' ? 'pass' : 'warn',
            "XDR_CORRELATION_ENGINE={$engine}", $ts);

        return $g;
    }

    private function phase2Gates(string $planId, string $ts): array
    {
        $domains = DomainSoakHarnessService::SUPPORTED_DOMAINS;
        $available = Schema::hasTable('advisory_findings') && Schema::hasTable('shadow_soak_runs');
        $diagnosticReady = [];
        $verifiedSources = [];
        if ($available) {
            foreach ($domains as $domain) {
                $report = $this->harness->getPreflightStatus($domain);
                if (($report['preflight_ready'] ?? false) === true) {
                    $diagnosticReady[] = $domain;
                }
                if (($report['promotion_evidence_verified'] ?? false) === true) {
                    $verifiedSources[] = $domain;
                }
            }
        }
        $unverified = array_diff($domains, $verifiedSources);
        $g[] = $this->gate($planId, 2, 'SPG-P2-01', 'Advisory diagnostic prerequisites by domain',
            count($diagnosticReady) === count($domains) ? 'pass' : 'warn',
            'diagnostic_ready=' . (implode(',', $diagnosticReady) ?: 'none'), $ts);
        $g[] = $this->gate($planId, 2, 'SPG-P2-02', 'Representative source evidence verified',
            $unverified === [] ? 'pass' : 'fail',
            $unverified === [] ? 'Independent source evidence verified'
                : 'unverified=' . implode(',', $unverified) . '; fixtures and diagnostic counts do not qualify',
            $ts);
        $g[] = $this->gate($planId, 2, 'SPG-P2-03', 'Advisory diagnostic tables available',
            $available ? 'pass' : 'fail',
            $available ? 'advisory finding and soak tables present' : 'advisory finding or soak table unavailable', $ts);

        // P2-04: safety constant — PROMOTION_RECOMMENDED = false
        $safe = \App\Services\DomainSoakSimulationService::PROMOTION_RECOMMENDED === false;
        $g[] = $this->gate($planId, 2, 'SPG-P2-04', 'DomainSoakSimulationService::PROMOTION_RECOMMENDED = false',
            $safe ? 'pass' : 'fail',
            $safe ? 'safety constant confirmed' : 'SAFETY VIOLATION — constant changed', $ts);

        return $g;
    }

    private function phase3Gates(string $planId, string $ts): array
    {
        // P3-01: fixture batch run at least once
        $batches = Schema::hasTable('detection_fixture_batches')
            ? DB::table('detection_fixture_batches')->count()
            : 0;
        $g[] = $this->gate($planId, 3, 'SPG-P3-01', 'detection fixture batch has been run (E056)',
            $batches >= 1 ? 'pass' : 'warn',
            "batches_run={$batches} (run: php artisan rule:run-fixtures)", $ts);

        // P3-02: valid fixture results >= 12
        $valid = Schema::hasTable('detection_fixture_results')
            ? DB::table('detection_fixture_results')->where('fixture_valid', true)->count()
            : 0;
        $g[] = $this->gate($planId, 3, 'SPG-P3-02', 'valid fixture results in DB >= 12',
            $valid >= 12 ? 'pass' : 'warn',
            "valid_fixture_results={$valid}", $ts);

        // P3-03: empirical rules >= 12 (after E058 refresh)
        $empirical = Schema::hasTable('rule_fixture_backlogs')
            ? DB::table('rule_fixture_backlogs')->where('confidence_source', 'empirical')->count()
            : 0;
        $g[] = $this->gate($planId, 3, 'SPG-P3-03', 'confidence_source=empirical >= 12 (after rule:refresh-confidence)',
            $empirical >= 12 ? 'pass' : 'warn',
            "empirical_count={$empirical}", $ts);

        // P3-04: DetectionReplayFixtureService available
        $svc = class_exists(\App\Services\DetectionReplayFixtureService::class);
        $g[] = $this->gate($planId, 3, 'SPG-P3-04', 'DetectionReplayFixtureService deployed (E056)',
            $svc ? 'pass' : 'fail',
            $svc ? 'service loadable' : 'service missing — E056 not complete', $ts);

        return $g;
    }

    private function phase4Gates(string $planId, string $ts, array $priorPhases): array
    {
        foreach (['SPG-P4-01' => 1, 'SPG-P4-02' => 2, 'SPG-P4-03' => 3] as $id => $phase) {
            $status = $priorPhases[$phase - 1]['readiness_status'] ?? 'BLOCKED';
            $g[] = $this->gate($planId, 4, $id, "Phase {$phase} diagnostic gates pass",
                match ($status) {
                    self::DIAGNOSTIC_READY => 'pass',
                    'BLOCKED' => 'fail',
                    default => 'warn',
                }, "phase_{$phase}_status={$status}", $ts);
        }

        // P4-04: endpoint shadow rules in registry
        $registryPath  = base_path('docs/detection/rules/registry.v1.json');
        $endpointCount = 0;
        if (is_readable($registryPath)) {
            $raw = file_get_contents($registryPath);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (isset($data['rules']) && is_array($data['rules'])) {
                $endpointCount = count(array_filter($data['rules'],
                    fn ($r) => is_array($r) && ($r['domain'] ?? '') === 'endpoint' && ($r['status'] ?? '') === 'shadow'
                ));
            }
        }
        $g[] = $this->gate($planId, 4, 'SPG-P4-04', 'endpoint shadow rules in registry >= 1',
            $endpointCount >= 1 ? 'pass' : 'warn',
            "endpoint_shadow_rules={$endpointCount}", $ts);

        return $g;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function gate(string $planId, int $phase, string $id, string $name, string $status, string $evidence, string $ts): array
    {
        return [
            'plan_run_id'  => $planId,
            'phase_number' => $phase,
            'gate_id'      => $id,
            'gate_name'    => $name,
            'passed'       => $status === 'pass',
            'status'       => $status,
            'evidence'     => $evidence,
            'is_advisory'  => true,
            'checked_at'   => $ts,
        ];
    }

    private function persist(array $plan, array $phases, array $gates, array $notes, string $ts): void
    {
        SoakPlanRun::create($plan);

        foreach ($phases as $phase) {
            DB::table('soak_plan_phases')->insert($phase);
        }
        foreach ($gates as $gate) {
            DB::table('soak_plan_gates')->insert($gate);
        }
        foreach ($notes as $note) {
            DB::table('soak_plan_readiness_notes')->insert($note);
        }

        DB::table('soak_plan_audit_events')->insert([
            'plan_run_id' => $plan['plan_run_id'],
            'action'      => 'plan_built',
            'detail'      => "phases={$plan['phases_total']} total_gates={$plan['total_gates']} readiness={$plan['overall_readiness']}",
            'is_advisory' => true,
            'recorded_at' => $ts,
        ]);
    }
}
