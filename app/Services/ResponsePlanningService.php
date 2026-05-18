<?php

namespace App\Services;

use App\Models\ResponsePlan;
use App\Models\ResponsePlanAction;
use App\Models\ResponsePlanApproval;
use App\Models\ResponsePlanNote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manual Response Planning & Recommendation Service.
 *
 * CRITICAL CONSTRAINTS:
 * - This service is planning and documentation ONLY.
 * - No containment actions are executed.
 * - No host isolation commands are sent.
 * - No process kill signals are generated.
 * - No external API calls are made.
 * - No infrastructure state is mutated.
 * - completed_documented = analyst documented manual external action.
 *
 * The recommendation engine is deterministic rule-based.
 * No LLM calls. No randomness.
 */
class ResponsePlanningService
{
    /**
     * Create a new response plan.
     */
    public function createPlan(array $data, int $creatorId): ResponsePlan
    {
        return DB::transaction(function () use ($data, $creatorId) {
            $plan = ResponsePlan::create([
                'plan_id'              => $this->generateId(),
                'title'                => $data['title'],
                'description'          => $data['description'] ?? null,
                'state'                => 'draft',
                'risk_level'           => $data['risk_level'] ?? null,
                'investigation_id'     => $data['investigation_id'] ?? null,
                'trace_id'             => $data['trace_id'] ?? null,
                'entity_ids'           => !empty($data['entity_ids']) ? $data['entity_ids'] : null,
                'related_alert_ids'    => !empty($data['related_alert_ids']) ? $data['related_alert_ids'] : null,
                'related_incident_ids' => !empty($data['related_incident_ids']) ? $data['related_incident_ids'] : null,
                'created_by'           => $creatorId,
                'assigned_approver'    => $data['assigned_approver'] ?? null,
                'metadata'             => $data['metadata'] ?? null,
            ]);

            $this->recordApproval($plan->id, $creatorId, 'submitted', null, 'Plan created', null, 'draft');

            if (!empty($data['actions'])) {
                foreach ($data['actions'] as $action) {
                    $this->addAction($plan->id, $action, $creatorId);
                }
            }

            return $plan;
        });
    }

    /**
     * Deterministic recommendation engine.
     * Read-only — no DB writes. Same entity data → same recommendations.
     *
     * @return array<int, array{action_type: string, target_entity_type: string, ...}>
     */
    public function generateRecommendationsForEntity(int $entityId): array
    {
        $entity = DB::table('entities')->where('id', $entityId)->first();
        if (!$entity) {
            return [];
        }

        $riskFactors = $entity->risk_factors ? json_decode($entity->risk_factors, true) : [];
        $factorNames = array_column($riskFactors ?? [], 'factor');
        $riskLevel   = $entity->risk_level ?? 'low';

        $recommendations = [];

        switch ($entity->entity_type) {
            case 'user':
                $this->applyUserRules($entity, $factorNames, $riskLevel, $riskFactors, $recommendations);
                break;

            case 'host':
                $this->applyHostRules($entity, $factorNames, $riskFactors, $recommendations);
                break;

            case 'ip':
                $this->applyIpRules($entity, $factorNames, $riskLevel, $riskFactors, $recommendations);
                break;

            case 'domain':
                $this->applyDomainRules($entity, $factorNames, $riskFactors, $recommendations);
                break;
        }

        // Universal: incident involvement on non-user entities
        if (in_array('incident_involvement', $factorNames, true) && $entity->entity_type !== 'user') {
            if (!$this->hasType($recommendations, 'recommend_collect_forensics')) {
                $recommendations[] = $this->rec(
                    'recommend_collect_forensics', $entity,
                    'Incident involvement detected. Forensic evidence collection recommended.',
                    $this->evidenceRefs($riskFactors, 'incident_involvement')
                );
            }
        }

        // Fallback: no recommendations → monitor_only
        if (empty($recommendations)) {
            $recommendations[] = $this->rec(
                'recommend_monitor_only', $entity,
                'Current risk profile does not require immediate action. Enhanced monitoring recommended.',
                []
            );
        }

        return $recommendations;
    }

    /**
     * Generate recommendations aggregated from all entities in an investigation.
     * Deterministic — reads investigation.entity_ids and generates per-entity recs.
     */
    public function generateRecommendationsForInvestigation(int $investigationId): array
    {
        $investigation = DB::table('investigations')->where('id', $investigationId)->first();
        if (!$investigation) {
            return [];
        }

        $entityIds = $investigation->entity_ids
            ? json_decode($investigation->entity_ids, true)
            : [];

        $all = [];
        foreach ($entityIds as $eid) {
            $recs = $this->generateRecommendationsForEntity((int) $eid);
            foreach ($recs as $rec) {
                $all[] = $rec;
            }
        }

        return $all;
    }

    /**
     * Add a planning action to a plan. Documentation only — no execution.
     */
    public function addAction(int $planId, array $data, int $actorId): ResponsePlanAction
    {
        return ResponsePlanAction::create([
            'response_plan_id'   => $planId,
            'action_type'        => $data['action_type'],
            'target_entity_type' => $data['target_entity_type'] ?? null,
            'target_entity_key'  => $data['target_entity_key']  ?? null,
            'target_entity_id'   => $data['target_entity_id']   ?? null,
            'reasoning'          => $data['reasoning']          ?? null,
            'evidence_refs'      => $data['evidence_refs']      ?? null,
            'advisory_only'      => (bool) ($data['advisory_only'] ?? false),
            'status'             => 'pending',
            'created_by'         => $actorId,
        ]);
    }

    /**
     * Submit plan for approval. Transitions: draft → pending_approval.
     */
    public function submitForApproval(int $planId, int $actorId): void
    {
        $this->transition($planId, 'pending_approval', $actorId, 'submitted');
    }

    /**
     * Approve a plan. Transitions: pending_approval → approved.
     * NO execution is triggered. Documentation only.
     */
    public function approve(int $planId, int $approverUserId, ?string $notes = null): void
    {
        DB::transaction(function () use ($planId, $approverUserId, $notes) {
            $plan = ResponsePlan::lockForUpdate()->findOrFail($planId);
            $this->assertValidTransition($plan->state, 'approved');

            $from = $plan->state;
            $plan->update(['state' => 'approved', 'approved_at' => now()->toDateTimeString()]);

            ResponsePlanApproval::create([
                'response_plan_id' => $planId,
                'approver_user_id' => $approverUserId,
                'decision'         => 'approved',
                'decision_at'      => now()->toDateTimeString(),
                'notes'            => $notes,
                'from_state'       => $from,
                'to_state'         => 'approved',
            ]);

            // Approve all pending actions as recommended (not executed)
            DB::table('response_plan_actions')
                ->where('response_plan_id', $planId)
                ->where('status', 'pending')
                ->update([
                    'status'      => 'approved',
                    'approved_by' => $approverUserId,
                    'approved_at' => now()->toDateTimeString(),
                ]);
        });
    }

    /**
     * Reject a plan. Transitions: pending_approval → rejected.
     */
    public function reject(int $planId, int $approverUserId, string $reason): void
    {
        DB::transaction(function () use ($planId, $approverUserId, $reason) {
            $plan = ResponsePlan::lockForUpdate()->findOrFail($planId);
            $this->assertValidTransition($plan->state, 'rejected');

            $from = $plan->state;
            $plan->update([
                'state'            => 'rejected',
                'rejected_at'      => now()->toDateTimeString(),
                'rejection_reason' => $reason,
            ]);

            ResponsePlanApproval::create([
                'response_plan_id' => $planId,
                'approver_user_id' => $approverUserId,
                'decision'         => 'rejected',
                'decision_at'      => now()->toDateTimeString(),
                'notes'            => $reason,
                'from_state'       => $from,
                'to_state'         => 'rejected',
            ]);
        });
    }

    /**
     * Mark plan as completed — analyst documents that manual actions were taken externally.
     * Transitions: approved → completed_documented.
     * NO system action is performed. Documentation only.
     */
    public function markCompleted(int $planId, int $actorId, ?string $notes = null): void
    {
        DB::transaction(function () use ($planId, $actorId, $notes) {
            $plan = ResponsePlan::lockForUpdate()->findOrFail($planId);
            $this->assertValidTransition($plan->state, 'completed_documented');

            $from = $plan->state;
            $plan->update(['state' => 'completed_documented', 'completed_at' => now()->toDateTimeString()]);

            ResponsePlanApproval::create([
                'response_plan_id' => $planId,
                'approver_user_id' => $actorId,
                'decision'         => 'completed',
                'decision_at'      => now()->toDateTimeString(),
                'notes'            => $notes ?? 'Analyst documented manual external completion.',
                'from_state'       => $from,
                'to_state'         => 'completed_documented',
            ]);
        });
    }

    /**
     * Cancel a plan. Works from any non-terminal state.
     */
    public function cancel(int $planId, int $actorId): void
    {
        $this->transition($planId, 'cancelled', $actorId, 'cancelled');
    }

    /**
     * Add a note. Always inserts — append-only.
     */
    public function addNote(int $planId, int $actorId, string $type, string $body): ResponsePlanNote
    {
        return ResponsePlanNote::create([
            'response_plan_id' => $planId,
            'author_user_id'   => $actorId,
            'note_type'        => $type,
            'body'             => $body,
        ]);
    }

    /**
     * Get plan timeline: approvals + notes merged and sorted by timestamp.
     */
    public function getTimeline(int $planId): Collection
    {
        $approvals = DB::table('response_plan_approvals as a')
            ->leftJoin('users', 'users.id', '=', 'a.approver_user_id')
            ->select('a.*', 'users.name as actor_name', DB::raw("'approval' as timeline_type"))
            ->where('a.response_plan_id', $planId)
            ->get()
            ->map(fn ($r) => (object) array_merge((array) $r, ['occurred_at' => $r->decision_at]));

        $notes = DB::table('response_plan_notes as n')
            ->leftJoin('users', 'users.id', '=', 'n.author_user_id')
            ->select('n.*', 'users.name as actor_name', DB::raw("'note' as timeline_type"))
            ->where('n.response_plan_id', $planId)
            ->get()
            ->map(fn ($r) => (object) array_merge((array) $r, ['occurred_at' => $r->created_at]));

        return $approvals->merge($notes)->sortBy('occurred_at')->values();
    }

    public function list(array $filters = [], int $limit = 100): Collection
    {
        $query = DB::table('response_plans as rp')
            ->leftJoin('users as cr', 'cr.id', '=', 'rp.created_by')
            ->leftJoin('users as ap', 'ap.id', '=', 'rp.assigned_approver')
            ->select('rp.*', 'cr.name as creator_name', 'ap.name as approver_name')
            ->orderByDesc('rp.created_at')
            ->limit($limit);

        if (!empty($filters['state'])) {
            $query->where('rp.state', $filters['state']);
        } elseif (empty($filters['include_terminal'])) {
            $query->whereNotIn('rp.state', ['completed_documented', 'cancelled']);
        }
        if (!empty($filters['risk_level'])) {
            $query->where('rp.risk_level', $filters['risk_level']);
        }
        if (!empty($filters['created_by'])) {
            $query->where('rp.created_by', $filters['created_by']);
        }
        if (!empty($filters['investigation_id'])) {
            $query->where('rp.investigation_id', $filters['investigation_id']);
        }

        return $query->get();
    }

    public function getStateCounts(): Collection
    {
        return DB::table('response_plans')
            ->select('state', DB::raw('count(*) as count'))
            ->groupBy('state')
            ->get()
            ->keyBy('state');
    }

    /**
     * Transition a rejected plan back to draft for revision.
     * Alias for: rejected → draft
     */
    public function transition_to_draft(int $planId, int $actorId): void
    {
        $this->transition($planId, 'draft', $actorId, 'revised');
    }

    public function isValidTransition(string $from, string $to): bool
    {
        return in_array($to, ResponsePlan::STATE_TRANSITIONS[$from] ?? [], true);
    }

    // -------------------------------------------------------------------------
    // Recommendation rules — deterministic, no side effects
    // -------------------------------------------------------------------------

    private function applyUserRules(
        object $entity, array $factorNames, string $riskLevel,
        array $riskFactors, array &$out
    ): void {
        if ($riskLevel === 'critical') {
            $out[] = $this->rec(
                'recommend_reset_password', $entity,
                'Critical user risk level detected. Immediate password reset recommended to revoke potentially compromised credentials.',
                $this->evidenceRefs($riskFactors, 'mfa_failure_burst', 'critical_alerts')
            );
        }

        if (in_array($riskLevel, ['high', 'critical'], true)) {
            $out[] = $this->rec(
                'recommend_revoke_session', $entity,
                'High/critical risk user activity detected. Active session revocation recommended.',
                $this->evidenceRefs($riskFactors, 'critical_alerts', 'high_alerts', 'mfa_failure_burst')
            );
        }

        if ($riskLevel === 'critical' && in_array('incident_involvement', $factorNames, true)) {
            $out[] = $this->rec(
                'recommend_disable_user', $entity,
                'Critical risk user confirmed in active incident. Account disable recommended pending investigation outcome.',
                $this->evidenceRefs($riskFactors, 'incident_involvement')
            );
        }

        if (in_array('mfa_failure_burst', $factorNames, true)
            && !in_array($riskLevel, ['high', 'critical'], true)
            && !$this->hasType($out, 'recommend_monitor_only')
        ) {
            $out[] = $this->rec(
                'recommend_monitor_only', $entity,
                'MFA failure burst at moderate risk level. Enhanced account monitoring recommended.',
                $this->evidenceRefs($riskFactors, 'mfa_failure_burst')
            );
        }
    }

    private function applyHostRules(
        object $entity, array $factorNames, array $riskFactors, array &$out
    ): void {
        if (in_array('persistence_indicator', $factorNames, true)) {
            $out[] = $this->rec(
                'recommend_collect_forensics', $entity,
                'Persistence indicator detected on host. Forensic image collection recommended before remediation.',
                $this->evidenceRefs($riskFactors, 'persistence_indicator'),
                true // advisory_only — derived from shadow endpoint alerts
            );
            $out[] = $this->rec(
                'recommend_remove_persistence', $entity,
                'Persistence mechanism detected. Manual investigation and removal recommended.',
                $this->evidenceRefs($riskFactors, 'persistence_indicator'),
                true // advisory_only
            );
        }

        if (in_array('c2_indicator', $factorNames, true)) {
            if (!$this->hasType($out, 'recommend_collect_forensics')) {
                $out[] = $this->rec(
                    'recommend_collect_forensics', $entity,
                    'C2 beacon pattern detected. Immediate forensic collection recommended.',
                    $this->evidenceRefs($riskFactors, 'c2_indicator'),
                    true // advisory_only
                );
            }
            $out[] = $this->rec(
                'recommend_isolate_host', $entity,
                'C2 communication pattern observed. Host network isolation evaluation recommended — manual approval and verification required.',
                $this->evidenceRefs($riskFactors, 'c2_indicator'),
                true // advisory_only from shadow
            );
        }

        if (in_array('shadow_alert_advisory', $factorNames, true)
            && !$this->hasType($out, 'recommend_monitor_only')
        ) {
            $out[] = $this->rec(
                'recommend_monitor_only', $entity,
                'Shadow endpoint alerts detected. Enhanced monitoring and investigation recommended pending full domain activation.',
                [],
                true // advisory_only
            );
        }
    }

    private function applyIpRules(
        object $entity, array $factorNames, string $riskLevel,
        array $riskFactors, array &$out
    ): void {
        $alertCount = DB::table('security_alerts')
            ->where('ip', $entity->entity_key)
            ->count();

        if ($alertCount >= 3 || in_array($riskLevel, ['high', 'critical'], true)) {
            $out[] = $this->rec(
                'recommend_block_ip', $entity,
                "IP address has {$alertCount} associated alert(s) at {$riskLevel} risk level. IP block evaluation recommended.",
                $this->evidenceRefs($riskFactors, 'critical_alerts', 'high_alerts')
            );
        }

        if (in_array('c2_indicator', $factorNames, true)) {
            $out[] = $this->rec(
                'recommend_block_ip', $entity,
                'C2 communication attributed to this IP. Blocking recommended pending confirmation.',
                $this->evidenceRefs($riskFactors, 'c2_indicator'),
                true // advisory_only
            );
        }

        if (empty($out)) {
            $out[] = $this->rec(
                'recommend_monitor_only', $entity,
                'IP risk level does not warrant immediate blocking. Continued monitoring recommended.',
                []
            );
        }
    }

    private function applyDomainRules(
        object $entity, array $factorNames, array $riskFactors, array &$out
    ): void {
        if (in_array('c2_indicator', $factorNames, true)) {
            $out[] = $this->rec(
                'recommend_block_domain', $entity,
                'Domain flagged as potential C2 communication target. DNS/firewall block evaluation recommended.',
                $this->evidenceRefs($riskFactors, 'c2_indicator'),
                true // advisory_only from shadow
            );
        } else {
            $out[] = $this->rec(
                'recommend_block_domain', $entity,
                'Domain entity involved in security alerts. Domain block evaluation recommended.',
                $this->evidenceRefs($riskFactors, 'critical_alerts', 'high_alerts')
            );
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function rec(
        string $actionType,
        object $entity,
        string $reasoning,
        array  $evidenceRefs = [],
        bool   $advisoryOnly = false
    ): array {
        return [
            'action_type'        => $actionType,
            'target_entity_type' => $entity->entity_type,
            'target_entity_key'  => $entity->entity_key,
            'target_entity_id'   => (int) $entity->id,
            'reasoning'          => $reasoning,
            'evidence_refs'      => $evidenceRefs,
            'advisory_only'      => $advisoryOnly,
        ];
    }

    private function evidenceRefs(array $riskFactors, string ...$names): array
    {
        $refs = [];
        foreach ($riskFactors as $factor) {
            if (!in_array($factor['factor'], $names, true)) {
                continue;
            }
            foreach (['alert_ids', 'incident_ids', 'trace_ids'] as $key) {
                if (!empty($factor[$key])) {
                    $refs[$key] = array_values(array_unique(
                        array_merge($refs[$key] ?? [], $factor[$key])
                    ));
                }
            }
        }
        return $refs;
    }

    private function hasType(array $recs, string $type): bool
    {
        return in_array($type, array_column($recs, 'action_type'), true);
    }

    private function assertValidTransition(string $from, string $to): void
    {
        if (!$this->isValidTransition($from, $to)) {
            throw new \InvalidArgumentException(
                "Invalid response plan transition: {$from} → {$to}"
            );
        }
    }

    private function transition(int $planId, string $toState, int $actorId, string $decision): void
    {
        DB::transaction(function () use ($planId, $toState, $actorId, $decision) {
            $plan = ResponsePlan::lockForUpdate()->findOrFail($planId);
            $this->assertValidTransition($plan->state, $toState);

            $from = $plan->state;
            $plan->update(['state' => $toState]);

            ResponsePlanApproval::create([
                'response_plan_id' => $planId,
                'approver_user_id' => $actorId,
                'decision'         => $decision,
                'decision_at'      => now()->toDateTimeString(),
                'from_state'       => $from,
                'to_state'         => $toState,
            ]);
        });
    }

    private function recordApproval(
        int $planId, int $actorId, string $decision,
        ?string $notes, string $fromState = null, string $toState = null
    ): void {
        ResponsePlanApproval::create([
            'response_plan_id' => $planId,
            'approver_user_id' => $actorId,
            'decision'         => $decision,
            'decision_at'      => now()->toDateTimeString(),
            'notes'            => $notes,
            'from_state'       => $fromState,
            'to_state'         => $toState,
        ]);
    }

    private function generateId(): string
    {
        $year  = now()->year;
        $count = DB::table('response_plans')->count() + 1;
        return sprintf('RP-%d-%05d', $year, $count);
    }
}
