<?php

namespace App\Services;

/**
 * META-MODULE-RATIONALIZE: a read-only facade over the 7 soak services
 * (`docs/architecture/META_MODULE_AUDIT.md` §4), mirroring
 * StabilityFreezeOverviewService's pattern — but deliberately NOT the same
 * shape, because these 7 services are confirmed structurally distinct
 * (harness=evidence-accumulation, simulation=dry-run, plan=tiering,
 * execution=the-real-run, freeze=snapshot-of-a-run, real-plan=multi-phase-
 * rollout-structure, chaos=fault-injection/resilience), unlike
 * StabilityEvidenceFreeze V2/V3/V4 which are the SAME kind of evidence
 * over sequential phase ranges. There is no single "current soak status"
 * across all 7 the way there's a single "most recent freeze" — this
 * facade answers "what does each of these 7 things currently say,"
 * honestly labelled per-service, not a merged status.
 *
 * Read-only: calls each service's own existing read method, writes
 * nothing, no autonomous action.
 */
class SoakOverviewService
{
    public function __construct(
        private readonly DomainSoakHarnessService $harness,
        private readonly DomainSoakSimulationService $simulation,
        private readonly EndpointSoakPlanService $endpointPlan,
        private readonly Phase1SoakExecutionService $phase1Execution,
        private readonly Phase1SoakEvidenceFreezeService $phase1Freeze,
        private readonly RealDomainSoakPlanService $realPlan,
        private readonly SoakChaosValidationService $chaos,
    ) {
    }

    public function overview(): array
    {
        $latestSimulation = $this->simulation->getSimulations()->first();

        return [
            'services' => [
                'domain_soak_harness' => [
                    'kind' => 'evidence_accumulation_summary',
                    'data' => $this->harness->getSummary(),
                ],
                'domain_soak_simulation' => [
                    'kind' => 'dry_run_simulation_latest',
                    'data' => $latestSimulation?->toArray(),
                ],
                'endpoint_soak_plan' => [
                    'kind' => 'tiering_plan_latest',
                    'data' => $this->endpointPlan->getLatestPlan(),
                ],
                'phase1_soak_execution' => [
                    'kind' => 'real_run_latest',
                    'data' => $this->phase1Execution->getLatestRun(),
                ],
                'phase1_soak_evidence_freeze' => [
                    'kind' => 'frozen_snapshot_latest',
                    'data' => $this->phase1Freeze->getLatestFreeze(),
                ],
                'real_domain_soak_plan' => [
                    'kind' => 'multi_phase_rollout_plan_latest',
                    'data' => $this->realPlan->getLatestPlan(),
                ],
                'soak_chaos_validation' => [
                    'kind' => 'chaos_fault_injection_dashboard',
                    'data' => $this->chaos->dashboardStats(),
                ],
            ],
            'is_advisory' => true,
            'note' => 'These 7 services intentionally track different things '
                .'(evidence accumulation, dry-run simulation, tiering plan, real '
                .'execution, frozen snapshot, multi-phase rollout structure, '
                .'chaos/fault-injection) — this is a read-only survey of each '
                .'service\'s own current status, not a single merged soak status.',
        ];
    }
}
