<?php

namespace App\Services;

use App\Models\SoakPlanRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-060: Real Domain Soak Execution Plan
 *
 * Structures the 4-phase soak plan starting from the smallest/safest scope:
 *   Phase 1 — 12 staged_active empirical rules: live proof / soak repeat
 *   Phase 2 — top-12 shadow-ready rules: first real soak evidence
 *   Phase 3 — 12 tier_1 fixture-backed rules: replay validation
 *   Phase 4 — endpoint tier_1 next batch (requires Phase 1-3 complete)
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

    private const PHASE_DEFINITIONS = [
        1 => [
            'name'         => 'Staged-Active Empirical Rules — Live Proof Repeat',
            'rule_scope'   => 'staged_active (identity/cloud/saas — 12 empirical rules)',
            'rule_count'   => 12,
            'purpose'      => 'Re-confirm all 12 staged_active rules fire on live traffic after E056 fixture validation. Lowest risk — rules already active.',
            'soak_command' => '.\\scripts\\run_xdr_correlation_soak_6h.ps1',
        ],
        2 => [
            'name'         => 'Top Shadow-Ready Rules — Real Soak Evidence',
            'rule_scope'   => 'shadow (endpoint/network/threat-intel — top-12 by confidence)',
            'rule_count'   => 12,
            'purpose'      => 'Generate first real soak evidence for highest-confidence shadow rules. Promotion remains BLOCKED until 6h soak PASS.',
            'soak_command' => '.\\scripts\\run_xdr_correlation_soak_6h.ps1 --domain=endpoint-shadow',
        ],
        3 => [
            'name'         => 'Tier-1 Fixture-Backed Rules — Replay Validation',
            'rule_scope'   => 'tier_1_immediate (has_replay_fixture=true, confidence_source=empirical)',
            'rule_count'   => 12,
            'purpose'      => 'Replay tier_1 fixture events through live pipeline. Confirms fixture→alert correlation chain end-to-end.',
            'soak_command' => 'php artisan rule:run-fixtures --tier=tier_1_immediate',
        ],
        4 => [
            'name'         => 'Endpoint Tier-1 Next Batch',
            'rule_scope'   => 'endpoint shadow (tier_2_next_batch — count TBD from registry)',
            'rule_count'   => 0,
            'purpose'      => 'After Phase 1-3 complete, build tier_2 fixtures and run endpoint soak for next batch. Requires all prior phases READY.',
            'soak_command' => '.\\scripts\\run_xdr_correlation_soak_6h.ps1 --domain=endpoint-tier2',
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
            $phaseGates  = $this->evaluatePhaseGates($planId, $phaseNum, $ts);
            $gates       = array_merge($gates, $phaseGates);

            $passed  = count(array_filter($phaseGates, fn ($g) => $g['status'] === 'pass'));
            $failed  = count(array_filter($phaseGates, fn ($g) => $g['status'] === 'fail'));
            $total   = count($phaseGates);

            $readiness = match(true) {
                $failed > 0         => 'BLOCKED',
                $passed === $total  => 'READY',
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
                'note_type'    => 'soak_command',
                'note_text'    => "Phase {$phaseNum} soak command: {$def['soak_command']}",
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

        $phasesReady   = count(array_filter($phases, fn ($p) => $p['readiness_status'] === 'READY'));
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
            'overall_readiness'  => $phasesBlocked === 0 ? 'READY_TO_SCHEDULE' : 'PARTIAL',
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
        $run = SoakPlanRun::orderByDesc('created_at')->first();
        if (!$run) {
            return null;
        }
        $planId = $run->plan_run_id;
        return [
            'plan'   => $run->toArray(),
            'phases' => DB::table('soak_plan_phases')->where('plan_run_id', $planId)->orderBy('phase_number')->get()->toArray(),
            'gates'  => DB::table('soak_plan_gates')->where('plan_run_id', $planId)->get()->toArray(),
            'notes'  => DB::table('soak_plan_readiness_notes')->where('plan_run_id', $planId)->get()->toArray(),
        ];
    }

    public function getPhaseDefinitions(): array
    {
        return self::PHASE_DEFINITIONS;
    }

    // ── Gate evaluation per phase ─────────────────────────────────────────────

    private function evaluatePhaseGates(string $planId, int $phase, string $ts): array
    {
        return match($phase) {
            1 => $this->phase1Gates($planId, $ts),
            2 => $this->phase2Gates($planId, $ts),
            3 => $this->phase3Gates($planId, $ts),
            4 => $this->phase4Gates($planId, $ts),
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
        // P2-01: domain soak simulations run for all 3 domains
        $simDomains = Schema::hasTable('domain_soak_simulations')
            ? DB::table('domain_soak_simulations')->distinct()->pluck('domain')->count()
            : 0;
        $g[] = $this->gate($planId, 2, 'SPG-P2-01', 'domain soak simulations run for 3 domains (E057)',
            $simDomains >= 3 ? 'pass' : 'warn',
            "domains_simulated={$simDomains} (run domain:soak-simulate)", $ts);

        // P2-02: endpoint structural_match_rate >= 0.80
        $epSim = Schema::hasTable('domain_soak_simulations')
            ? DB::table('domain_soak_simulations')
                ->where('domain', 'endpoint')
                ->orderByDesc('simulated_at')
                ->first()
            : null;
        $rate = $epSim ? round((float) $epSim->structural_match_rate, 3) : 0.0;
        $g[] = $this->gate($planId, 2, 'SPG-P2-02', 'endpoint structural_match_rate >= 0.80',
            $rate >= 0.80 ? 'pass' : 'warn',
            "endpoint_structural_match_rate={$rate}", $ts);

        // P2-03: DomainSoakHarnessService exists (BACKLOG-018)
        $harness = class_exists(\App\Services\DomainSoakHarnessService::class);
        $g[] = $this->gate($planId, 2, 'SPG-P2-03', 'DomainSoakHarnessService deployed (BACKLOG-018)',
            $harness ? 'pass' : 'warn',
            $harness ? 'harness service available' : 'harness missing — run BACKLOG-018 first', $ts);

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

    private function phase4Gates(string $planId, string $ts): array
    {
        // P4-01: Phase 1 proxy — fixture files on disk
        $dir   = base_path('tests/fixtures/detection/tier1_batch1');
        $count = is_dir($dir) ? count(glob($dir . '/*.json')) : 0;
        $g[] = $this->gate($planId, 4, 'SPG-P4-01', 'Phase 1 proxy ready (fixture files >= 12 on disk)',
            $count >= 12 ? 'pass' : 'warn',
            "fixture_files={$count}", $ts);

        // P4-02: Phase 2 proxy — domain simulations run
        $simDomains = Schema::hasTable('domain_soak_simulations')
            ? DB::table('domain_soak_simulations')->distinct()->pluck('domain')->count()
            : 0;
        $g[] = $this->gate($planId, 4, 'SPG-P4-02', 'Phase 2 proxy ready (domain sims >= 3)',
            $simDomains >= 3 ? 'pass' : 'warn',
            "domains_simulated={$simDomains}", $ts);

        // P4-03: Phase 3 proxy — fixture service + batch run
        $svc    = class_exists(\App\Services\DetectionReplayFixtureService::class);
        $batch  = Schema::hasTable('detection_fixture_batches')
            ? DB::table('detection_fixture_batches')->count()
            : 0;
        $g[] = $this->gate($planId, 4, 'SPG-P4-03', 'Phase 3 proxy ready (fixture service + batch run)',
            ($svc && $batch >= 1) ? 'pass' : 'warn',
            "service={$svc}, batches_run={$batch}", $ts);

        // P4-04: endpoint shadow rules in registry
        $registryPath  = base_path('docs/detection/rules/registry.v1.json');
        $endpointCount = 0;
        if (file_exists($registryPath)) {
            $data          = json_decode(file_get_contents($registryPath), true);
            $endpointCount = count(array_filter($data['rules'] ?? [],
                fn ($r) => ($r['domain'] ?? '') === 'endpoint' && ($r['status'] ?? '') === 'shadow'
            ));
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
