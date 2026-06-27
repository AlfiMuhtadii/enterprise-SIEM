<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Phase1SoakExecutionService
{
    public const ADVISORY_ONLY  = true;
    public const NO_PROMOTION   = true;
    public const SCOPE          = 'staged_active_empirical';
    public const DURATION_MIN   = 30;
    public const DURATION_MAX   = 60;
    public const GATES_TOTAL    = 8;

    private const GATES = [
        'P1G-01' => 'Staged-active rules count = 12',
        'P1G-02' => 'Correlation engine is Go (XDR_CORRELATION_ENGINE=go)',
        'P1G-03' => 'Tier-1 fixture files on disk >= 12',
        'P1G-04' => 'Empirical rules in backlog >= 1',
        'P1G-05' => 'DLQ error count = 0',
        'P1G-06' => 'Recent alerts > 0 (pipeline active, last 2h)',
        'P1G-07' => 'p95 latency < 300ms (advisory — measured via soak script)',
        'P1G-08' => 'Fallback count = 0 (advisory — measured via soak script)',
    ];

    private const REGISTRY_PATH = 'docs/detection/rules/registry.v1.json';
    private const FIXTURE_DIR   = 'detection/fixtures/tier_1';

    public function buildRun(bool $dryRun = true, int $durationMinutes = 30): array
    {
        $durationMinutes = max(self::DURATION_MIN, min(self::DURATION_MAX, $durationMinutes));
        $runId   = (string) Str::uuid();
        $gates   = $this->runGates($dryRun);
        $metrics = $this->collectMetrics($dryRun, $durationMinutes);
        $decision = $this->computeDecision($gates);

        $passed = count(array_filter($gates, fn ($g) => $g['status'] === 'pass'));
        $warned = count(array_filter($gates, fn ($g) => $g['status'] === 'warn'));
        $failed = count(array_filter($gates, fn ($g) => $g['status'] === 'fail'));

        $plan = [
            'soak_run_id'     => $runId,
            'scope'           => self::SCOPE,
            'duration_minutes' => $durationMinutes,
            'rules_in_scope'  => $this->countStagedActiveRules(),
            'gates_total'     => count($gates),
            'gates_passed'    => $passed,
            'gates_warned'    => $warned,
            'gates_failed'    => $failed,
            'decision'        => $decision,
            'no_promotion'    => self::NO_PROMOTION,
            'is_advisory'     => self::ADVISORY_ONLY,
            'is_dry_run'      => $dryRun,
        ];

        if (! $dryRun) {
            $this->persist($plan, $gates, $metrics);
        }

        return compact('plan', 'gates', 'metrics');
    }

    public function runGates(bool $dryRun = true): array
    {
        return [
            $this->gateP1G01(),
            $this->gateP1G02(),
            $this->gateP1G03(),
            $this->gateP1G04($dryRun),
            $this->gateP1G05($dryRun),
            $this->gateP1G06($dryRun),
            $this->gateP1G07($dryRun),
            $this->gateP1G08($dryRun),
        ];
    }

    public function computeDecision(array $gates): string
    {
        foreach ($gates as $gate) {
            if ($gate['status'] === 'fail') {
                return 'FAIL';
            }
        }
        foreach ($gates as $gate) {
            if ($gate['status'] === 'warn') {
                return 'WARN';
            }
        }
        return 'PASS';
    }

    public function getGateDefinitions(): array
    {
        return array_map(
            fn ($id, $name) => ['gate_id' => $id, 'gate_name' => $name],
            array_keys(self::GATES),
            array_values(self::GATES)
        );
    }

    public function getLatestRun(): ?array
    {
        $run = DB::table('phase1_soak_runs')->orderByDesc('id')->first();

        if (! $run) {
            return null;
        }

        $gates = DB::table('phase1_soak_gate_results')
            ->where('soak_run_id', $run->soak_run_id)
            ->get()
            ->toArray();

        $metrics = DB::table('phase1_soak_metrics')
            ->where('soak_run_id', $run->soak_run_id)
            ->get()
            ->toArray();

        return [
            'plan'    => (array) $run,
            'gates'   => array_map(fn ($g) => (array) $g, $gates),
            'metrics' => array_map(fn ($m) => (array) $m, $metrics),
        ];
    }

    // ── Structural gates (no DB — always computed) ────────────────────────────

    private function gateP1G01(): array
    {
        $count  = $this->countStagedActiveRules();
        $passed = $count >= 12;
        return [
            'gate_id'    => 'P1G-01',
            'gate_name'  => self::GATES['P1G-01'],
            'status'     => $passed ? 'pass' : 'fail',
            'passed'     => $passed,
            'evidence'   => "registry staged_active count={$count}",
            'is_advisory' => false,
        ];
    }

    private function gateP1G02(): array
    {
        $engine = env('XDR_CORRELATION_ENGINE', '');
        if ($engine === 'go') {
            $status = 'pass';
            $passed = true;
        } elseif ($engine === '') {
            $status = 'warn';
            $passed = false;
        } else {
            $status = 'fail';
            $passed = false;
        }
        return [
            'gate_id'    => 'P1G-02',
            'gate_name'  => self::GATES['P1G-02'],
            'status'     => $status,
            'passed'     => $passed,
            'evidence'   => 'XDR_CORRELATION_ENGINE=' . ($engine ?: '(not set)'),
            'is_advisory' => false,
        ];
    }

    private function gateP1G03(): array
    {
        $dir   = storage_path(self::FIXTURE_DIR);
        $files = glob($dir . '/*.json') ?: [];
        $count  = count($files);
        $passed = $count >= 12;
        return [
            'gate_id'    => 'P1G-03',
            'gate_name'  => self::GATES['P1G-03'],
            'status'     => $passed ? 'pass' : 'warn',
            'passed'     => $passed,
            'evidence'   => "tier_1 fixture files on disk={$count}",
            'is_advisory' => false,
        ];
    }

    // ── Live-DB gates (advisory in dry-run) ──────────────────────────────────

    private function gateP1G04(bool $dryRun): array
    {
        if ($dryRun) {
            return $this->advisoryGate('P1G-04', 'dry-run: empirical backlog count not measured');
        }
        $count  = DB::table('rule_fixture_backlogs')->where('confidence_source', 'empirical')->count();
        $passed = $count >= 1;
        return [
            'gate_id'    => 'P1G-04',
            'gate_name'  => self::GATES['P1G-04'],
            'status'     => $passed ? 'pass' : 'warn',
            'passed'     => $passed,
            'evidence'   => "rule_fixture_backlogs confidence_source=empirical count={$count}",
            'is_advisory' => true,
        ];
    }

    private function gateP1G05(bool $dryRun): array
    {
        if ($dryRun) {
            return $this->advisoryGate('P1G-05', 'dry-run: DLQ not checked');
        }
        $count  = DB::table('dlq_records')->where('status', 'error')->count();
        $passed = $count === 0;
        return [
            'gate_id'    => 'P1G-05',
            'gate_name'  => self::GATES['P1G-05'],
            'status'     => $passed ? 'pass' : 'fail',
            'passed'     => $passed,
            'evidence'   => "dlq_records status=error count={$count}",
            'is_advisory' => true,
        ];
    }

    private function gateP1G06(bool $dryRun): array
    {
        if ($dryRun) {
            return $this->advisoryGate('P1G-06', 'dry-run: recent alerts not checked');
        }
        $count  = DB::table('security_alerts')->where('created_at', '>=', now()->subHours(2))->count();
        $passed = $count > 0;
        return [
            'gate_id'    => 'P1G-06',
            'gate_name'  => self::GATES['P1G-06'],
            'status'     => $passed ? 'pass' : 'warn',
            'passed'     => $passed,
            'evidence'   => "security_alerts last 2h count={$count}",
            'is_advisory' => true,
        ];
    }

    // ── Always-advisory gates (require actual soak script output) ─────────────

    private function gateP1G07(bool $dryRun): array
    {
        $reason = $dryRun
            ? 'dry-run: latency not measured'
            : 'advisory: p95 latency measured via soak script output; instrument after first real soak';
        return $this->advisoryGate('P1G-07', $reason);
    }

    private function gateP1G08(bool $dryRun): array
    {
        $fallbackEnabled = env('XDR_CORRELATION_FALLBACK_TO_LEGACY', 'false');
        $reason = $dryRun
            ? 'dry-run: fallback count not measured'
            : "advisory: fallback_count measured via soak script output; XDR_CORRELATION_FALLBACK_TO_LEGACY={$fallbackEnabled}";
        return $this->advisoryGate('P1G-08', $reason);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function advisoryGate(string $gateId, string $reason): array
    {
        return [
            'gate_id'    => $gateId,
            'gate_name'  => self::GATES[$gateId],
            'status'     => 'warn',
            'passed'     => false,
            'evidence'   => $reason,
            'is_advisory' => true,
        ];
    }

    private function collectMetrics(bool $dryRun, int $durationMinutes): array
    {
        return [
            ['metric_key' => 'scope',           'metric_value' => self::SCOPE,                'unit' => null,      'within_bounds' => true,  'is_advisory' => false],
            ['metric_key' => 'no_promotion',    'metric_value' => 'true',                     'unit' => null,      'within_bounds' => true,  'is_advisory' => false],
            ['metric_key' => 'duration_min',    'metric_value' => (string) self::DURATION_MIN,'unit' => 'minutes', 'within_bounds' => true,  'is_advisory' => false],
            ['metric_key' => 'duration_max',    'metric_value' => (string) self::DURATION_MAX,'unit' => 'minutes', 'within_bounds' => true,  'is_advisory' => false],
            ['metric_key' => 'duration_target', 'metric_value' => (string) $durationMinutes,  'unit' => 'minutes', 'within_bounds' => true,  'is_advisory' => false],
            ['metric_key' => 'is_dry_run',      'metric_value' => $dryRun ? 'true' : 'false', 'unit' => null,      'within_bounds' => true,  'is_advisory' => true],
        ];
    }

    private function countStagedActiveRules(): int
    {
        $path = base_path(self::REGISTRY_PATH);
        if (! file_exists($path)) {
            return 0;
        }
        $registry = json_decode(file_get_contents($path), true);
        $rules    = $registry['rules'] ?? [];
        return count(array_filter($rules, fn ($r) => ($r['status'] ?? '') === 'staged_active'));
    }

    private function persist(array $plan, array $gates, array $metrics): void
    {
        DB::table('phase1_soak_runs')->insert([
            'soak_run_id'      => $plan['soak_run_id'],
            'scope'            => $plan['scope'],
            'duration_minutes' => $plan['duration_minutes'],
            'rules_in_scope'   => $plan['rules_in_scope'],
            'gates_total'      => $plan['gates_total'],
            'gates_passed'     => $plan['gates_passed'],
            'gates_warned'     => $plan['gates_warned'],
            'gates_failed'     => $plan['gates_failed'],
            'decision'         => $plan['decision'],
            'no_promotion'     => true,
            'is_advisory'      => true,
            'is_dry_run'       => $plan['is_dry_run'],
            'tenant_id'        => null,
        ]);

        foreach ($gates as $gate) {
            DB::table('phase1_soak_gate_results')->insert([
                'soak_run_id' => $plan['soak_run_id'],
                'gate_id'     => $gate['gate_id'],
                'gate_name'   => $gate['gate_name'],
                'status'      => $gate['status'],
                'passed'      => $gate['passed'],
                'evidence'    => $gate['evidence'] ?? null,
                'is_advisory' => $gate['is_advisory'] ?? true,
                'tenant_id'   => null,
            ]);
        }

        foreach ($metrics as $metric) {
            DB::table('phase1_soak_metrics')->insert([
                'soak_run_id'   => $plan['soak_run_id'],
                'metric_key'    => $metric['metric_key'],
                'metric_value'  => $metric['metric_value'],
                'unit'          => $metric['unit'] ?? null,
                'within_bounds' => $metric['within_bounds'],
                'is_advisory'   => $metric['is_advisory'],
            ]);
        }

        DB::table('phase1_soak_audit_events')->insert([
            'soak_run_id' => $plan['soak_run_id'],
            'action'      => 'phase1_soak_run',
            'detail'      => "decision={$plan['decision']} gates_failed={$plan['gates_failed']} dry_run=" . ($plan['is_dry_run'] ? 'true' : 'false'),
            'is_advisory' => true,
        ]);
    }
}
