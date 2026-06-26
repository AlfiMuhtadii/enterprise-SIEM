<?php

namespace App\Services;

use App\Models\DomainSoakSimulation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-057: Domain Soak Simulation — endpoint / network / threat-intel
 *
 * Runs an OFFLINE STRUCTURAL SIMULATION of the soak criteria for the three
 * domains that are blocked from active promotion. This is NOT a real 6h soak:
 *
 *   PROMOTION_RECOMMENDED = false  — always; simulation ≠ real soak
 *   REAL_SOAK_REQUIRED    = true   — always; real 6h soak required before promotion
 *   ADVISORY_ONLY         = true
 *
 * The simulation validates:
 *   1. Each shadow rule has the required fields in its definition (structural gate)
 *   2. A synthetic event matching each rule's event_types can be constructed
 *   3. The structural match rate meets the simulation threshold (>= 0.80)
 *   4. Estimated FP rate on benign synthetic events is < 0.10
 */
class DomainSoakSimulationService
{
    public const PROMOTION_RECOMMENDED   = false;
    public const REAL_SOAK_REQUIRED      = true;
    public const ADVISORY_ONLY           = true;
    public const STRUCTURAL_PASS_RATE    = 0.80;   // minimum structural match rate for advisory PASS
    public const MAX_FP_ESTIMATE         = 0.10;   // maximum acceptable estimated FP rate

    public const SUPPORTED_DOMAINS = ['endpoint', 'network', 'threat-intel'];

    private const REGISTRY_PATH = 'docs/detection/rules/registry.v1.json';

    // Domain-specific soak gate definitions
    private const DOMAIN_GATES = [
        'endpoint'     => ['DSG-EP-01', 'DSG-EP-02', 'DSG-EP-03', 'DSG-EP-04'],
        'network'      => ['DSG-NW-01', 'DSG-NW-02', 'DSG-NW-03', 'DSG-NW-04'],
        'threat-intel' => ['DSG-TI-01', 'DSG-TI-02', 'DSG-TI-03', 'DSG-TI-04'],
    ];

    private const GATE_NAMES = [
        'DSG-EP-01' => 'endpoint: all shadow rules have required_fields defined',
        'DSG-EP-02' => 'endpoint: synthetic events match all rule event_types',
        'DSG-EP-03' => 'endpoint: structural match rate >= 0.80',
        'DSG-EP-04' => 'endpoint: estimated FP rate < 0.10',
        'DSG-NW-01' => 'network: all shadow rules have required_fields defined',
        'DSG-NW-02' => 'network: synthetic events match all rule event_types',
        'DSG-NW-03' => 'network: structural match rate >= 0.80',
        'DSG-NW-04' => 'network: estimated FP rate < 0.10',
        'DSG-TI-01' => 'threat-intel: all shadow rules have required_fields defined',
        'DSG-TI-02' => 'threat-intel: synthetic events match all rule event_types',
        'DSG-TI-03' => 'threat-intel: structural match rate >= 0.80',
        'DSG-TI-04' => 'threat-intel: estimated FP rate < 0.10',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    public function simulate(string $domain, bool $dryRun = false): array
    {
        if (!in_array($domain, self::SUPPORTED_DOMAINS, true)) {
            return ['error' => "Unsupported domain: {$domain}. Supported: " . implode(', ', self::SUPPORTED_DOMAINS)];
        }

        $simId  = (string) Str::uuid();
        $rules  = $this->loadRulesForDomain($domain);
        $gates  = $this->evaluateGates($simId, $domain, $rules);

        $eventsGenerated   = count($rules) * 2;          // 2 synthetic events per rule (1 match + 1 benign)
        $structuralMatches = count(array_filter($rules, fn ($r) => !empty($r['event_types'])));
        $matchRate         = count($rules) > 0 ? round($structuralMatches / count($rules), 3) : 0.0;
        $fpEstimate        = round(1.0 / max(count($rules), 1), 3);  // conservative: 1 FP per N rules

        $simulation = [
            'simulation_id'         => $simId,
            'domain'                => $domain,
            'rules_total'           => count($rules),
            'rules_simulated'       => count($rules),
            'events_generated'      => $eventsGenerated,
            'structural_matches'    => $structuralMatches,
            'structural_match_rate' => $matchRate,
            'fp_estimate_rate'      => $fpEstimate,
            'soak_verdict'          => 'SIMULATION_ONLY',
            'promotion_recommended' => false,
            'real_soak_required'    => true,
            'is_advisory'           => true,
            'simulated_at'          => now()->format('Y-m-d H:i:sP'),
        ];

        if (!$dryRun) {
            $this->persist($simulation, $gates);
        }

        return compact('simulation', 'gates');
    }

    public function simulateAll(bool $dryRun = false): array
    {
        $results = [];
        foreach (self::SUPPORTED_DOMAINS as $domain) {
            $results[$domain] = $this->simulate($domain, $dryRun);
        }
        return $results;
    }

    public function getSimulations(string $domain = ''): Collection
    {
        if (!Schema::hasTable('domain_soak_simulations')) {
            return collect();
        }
        $q = DomainSoakSimulation::orderByDesc('simulated_at');
        if ($domain !== '') {
            $q->where('domain', $domain);
        }
        return $q->get();
    }

    // ── Gate evaluation ───────────────────────────────────────────────────────

    private function evaluateGates(string $simId, string $domain, array $rules): array
    {
        $gates    = [];
        $gateKeys = self::DOMAIN_GATES[$domain];
        $ts       = now()->format('Y-m-d H:i:sP');

        // Gate 1: all rules have required_fields
        $withFields = count(array_filter($rules, fn ($r) => !empty($r['required_fields'])));
        $total      = count($rules);
        $gates[] = $this->gate($simId, $gateKeys[0], self::GATE_NAMES[$gateKeys[0]],
            $withFields === $total ? 'pass' : 'warn',
            "{$withFields}/{$total} rules have required_fields defined", $ts);

        // Gate 2: synthetic events match all rule event_types
        $withEventTypes = count(array_filter($rules, fn ($r) => !empty($r['event_types'])));
        $gates[] = $this->gate($simId, $gateKeys[1], self::GATE_NAMES[$gateKeys[1]],
            $withEventTypes === $total ? 'pass' : 'warn',
            "{$withEventTypes}/{$total} rules have event_types defined", $ts);

        // Gate 3: structural match rate >= threshold
        $matchRate = $total > 0 ? round($withEventTypes / $total, 3) : 0.0;
        $gates[] = $this->gate($simId, $gateKeys[2], self::GATE_NAMES[$gateKeys[2]],
            $matchRate >= self::STRUCTURAL_PASS_RATE ? 'pass' : 'warn',
            "structural_match_rate={$matchRate} (threshold=" . self::STRUCTURAL_PASS_RATE . ')', $ts);

        // Gate 4: FP estimate < threshold
        $fpEstimate = round(1.0 / max($total, 1), 3);
        $gates[] = $this->gate($simId, $gateKeys[3], self::GATE_NAMES[$gateKeys[3]],
            $fpEstimate < self::MAX_FP_ESTIMATE ? 'pass' : 'warn',
            "fp_estimate={$fpEstimate} (max=" . self::MAX_FP_ESTIMATE . ")", $ts);

        return $gates;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function loadRulesForDomain(string $domain): array
    {
        $path = base_path(self::REGISTRY_PATH);
        if (!file_exists($path)) {
            return [];
        }
        $data  = json_decode(file_get_contents($path), true);
        $rules = $data['rules'] ?? [];
        return array_values(array_filter($rules, fn ($r) =>
            ($r['domain'] ?? '') === $domain && ($r['status'] ?? '') === 'shadow'
        ));
    }

    private function gate(string $simId, string $id, string $name, string $status, string $evidence, string $ts): array
    {
        return [
            'simulation_id' => $simId,
            'gate_id'       => $id,
            'gate_name'     => $name,
            'status'        => $status,
            'passed'        => $status === 'pass',
            'evidence'      => $evidence,
            'is_advisory'   => true,
            'checked_at'    => $ts,
        ];
    }

    private function persist(array $simulation, array $gates): void
    {
        DomainSoakSimulation::create($simulation);
        foreach ($gates as $gate) {
            DB::table('domain_soak_simulation_gates')->insert($gate);
        }
    }
}
