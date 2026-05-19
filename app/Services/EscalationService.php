<?php

namespace App\Services;

use App\Models\EscalationEvent;
use App\Models\Investigation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Escalation Service — SOC escalation routing and workflow governance.
 *
 * Analyst-driven collaborative workflows only. No autonomous SOC operations.
 * No automatic escalation execution. No autonomous analyst assignment.
 * All escalations require explicit operator action.
 */
class EscalationService
{
    // -----------------------------------------------------------------------
    // Escalation lifecycle
    // -----------------------------------------------------------------------

    /**
     * Create an escalation for an investigation.
     * Explicit operator action required — no automatic escalation.
     */
    public function createEscalation(
        string  $investigationId,
        User    $fromAnalyst,
        ?int    $toAnalystId,
        string  $reason,
        string  $severity,
        ?string $routingRule = null
    ): EscalationEvent {
        $escalationId = EscalationEvent::generateEscalationId();

        return EscalationEvent::create([
            'escalation_id'    => $escalationId,
            'investigation_id' => $investigationId,
            'event_type'       => EscalationEvent::EVENT_CREATED,
            'workflow_state'   => EscalationEvent::STATE_ESCALATED,
            'from_analyst_id'  => $fromAnalyst->id,
            'to_analyst_id'    => $toAnalystId,
            'severity'         => $severity,
            'reason'           => substr($reason, 0, 2000),
            'routing_rule'     => $routingRule,
            'trace_id'         => 'esc-' . Str::uuid(),
        ]);
    }

    /**
     * Acknowledge an escalation. Records acknowledgement — no auto-action.
     */
    public function acknowledgeEscalation(
        string  $escalationId,
        User    $analyst,
        ?string $note = null
    ): EscalationEvent {
        // Get latest event for this escalation
        $latest = EscalationEvent::where('escalation_id', $escalationId)
            ->orderByDesc('id')
            ->first();

        if (!$latest) {
            throw new \InvalidArgumentException("Escalation {$escalationId} not found.");
        }

        return EscalationEvent::create([
            'escalation_id'       => $escalationId,
            'investigation_id'    => $latest->investigation_id,
            'event_type'          => EscalationEvent::EVENT_ACKNOWLEDGED,
            'workflow_state'      => EscalationEvent::STATE_INVESTIGATING,
            'from_analyst_id'     => $latest->to_analyst_id,
            'to_analyst_id'       => $analyst->id,
            'severity'            => $latest->severity,
            'acknowledged_at'     => now(),
            'acknowledgement_note'=> $note ? substr($note, 0, 1000) : null,
            'trace_id'            => 'esc-' . Str::uuid(),
        ]);
    }

    /**
     * Reroute an escalation to a different analyst.
     * Explicit operator action — no autonomous routing.
     */
    public function rerouteEscalation(
        string  $escalationId,
        int     $newAnalystId,
        User    $reroutedBy,
        ?string $reason = null
    ): EscalationEvent {
        $latest = EscalationEvent::where('escalation_id', $escalationId)
            ->orderByDesc('id')
            ->first();

        if (!$latest) {
            throw new \InvalidArgumentException("Escalation {$escalationId} not found.");
        }

        return EscalationEvent::create([
            'escalation_id'   => $escalationId,
            'investigation_id'=> $latest->investigation_id,
            'event_type'      => EscalationEvent::EVENT_ROUTED,
            'workflow_state'  => EscalationEvent::STATE_ESCALATED,
            'from_analyst_id' => $reroutedBy->id,
            'to_analyst_id'   => $newAnalystId,
            'severity'        => $latest->severity,
            'reason'          => $reason ? substr($reason, 0, 1000) : 'Rerouted by operator',
            'routing_rule'    => 'manual_reroute',
            'trace_id'        => 'esc-' . Str::uuid(),
        ]);
    }

    /**
     * Transition an investigation workflow state.
     * Deterministic transitions enforced. Append-only record.
     */
    public function transitionWorkflowState(
        string  $investigationId,
        string  $fromState,
        string  $toState,
        User    $analyst,
        ?string $reason = null
    ): EscalationEvent {
        if (!EscalationEvent::canTransition($fromState, $toState)) {
            throw new \InvalidArgumentException(
                "Cannot transition from '{$fromState}' to '{$toState}'."
            );
        }

        return EscalationEvent::create([
            'escalation_id'   => 'wf-' . Str::uuid(),
            'investigation_id'=> $investigationId,
            'event_type'      => EscalationEvent::EVENT_WORKFLOW_STATE,
            'workflow_state'  => $toState,
            'from_analyst_id' => $analyst->id,
            'to_analyst_id'   => $analyst->id,
            'reason'          => $reason ? substr($reason, 0, 1000) : null,
            'trace_id'        => 'esc-' . Str::uuid(),
        ]);
    }

    /**
     * Mark escalation as resolved.
     */
    public function resolveEscalation(
        string  $escalationId,
        User    $analyst,
        ?string $resolutionNote = null
    ): EscalationEvent {
        $latest = EscalationEvent::where('escalation_id', $escalationId)
            ->orderByDesc('id')
            ->first();

        if (!$latest) {
            throw new \InvalidArgumentException("Escalation {$escalationId} not found.");
        }

        return EscalationEvent::create([
            'escalation_id'       => $escalationId,
            'investigation_id'    => $latest->investigation_id,
            'event_type'          => EscalationEvent::EVENT_RESOLVED,
            'workflow_state'      => EscalationEvent::STATE_RESOLVED,
            'from_analyst_id'     => $analyst->id,
            'acknowledgement_note'=> $resolutionNote ? substr($resolutionNote, 0, 1000) : null,
            'trace_id'            => 'esc-' . Str::uuid(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Timeout tracking — advisory only, no auto-action
    // -----------------------------------------------------------------------

    /**
     * Identify escalations that have exceeded their timeout threshold.
     * Advisory only — does NOT automatically reassign or close.
     */
    public function detectTimedOutEscalations(): Collection
    {
        $timedOut = [];

        $openEscalations = EscalationEvent::where('event_type', EscalationEvent::EVENT_CREATED)
            ->whereNull('acknowledged_at')
            ->get()
            ->groupBy('escalation_id');

        foreach ($openEscalations as $escalationId => $events) {
            $latest   = $events->sortByDesc('id')->first();
            $severity = $latest->severity ?? 'medium';
            $hoursLimit = EscalationEvent::ESCALATION_TIMEOUT_HOURS[$severity] ?? 24;

            if ($latest->created_at->addHours($hoursLimit)->isPast()) {
                $timedOut[] = [
                    'escalation_id'    => $escalationId,
                    'investigation_id' => $latest->investigation_id,
                    'severity'         => $severity,
                    'hours_overdue'    => round($latest->created_at->diffInHours(now()) - $hoursLimit, 1),
                    'to_analyst_id'    => $latest->to_analyst_id,
                    'advisory_only'    => true,
                    'no_auto_action'   => true,
                ];
            }
        }

        return collect($timedOut);
    }

    // -----------------------------------------------------------------------
    // Query helpers
    // -----------------------------------------------------------------------

    public function getEscalationQueue(?int $analystId = null): Collection
    {
        // Get open escalations (created but not yet resolved)
        $resolved = EscalationEvent::where('event_type', EscalationEvent::EVENT_RESOLVED)
            ->pluck('escalation_id')
            ->unique()
            ->toArray();

        $q = EscalationEvent::where('event_type', EscalationEvent::EVENT_CREATED)
            ->whereNotIn('escalation_id', $resolved)
            ->orderByDesc('id');

        if ($analystId) {
            $q->where('to_analyst_id', $analystId);
        }

        return $q->with(['fromAnalyst', 'toAnalyst'])->get();
    }

    public function getPendingEscalations(): Collection
    {
        return $this->getEscalationQueue();
    }

    public function getWorkflowStateHistory(string $investigationId): Collection
    {
        return EscalationEvent::where('investigation_id', $investigationId)
            ->orderBy('id')
            ->get();
    }

    public function getCurrentWorkflowState(string $investigationId): ?string
    {
        return EscalationEvent::where('investigation_id', $investigationId)
            ->whereNotNull('workflow_state')
            ->orderByDesc('id')
            ->value('workflow_state');
    }

    public function getRecentEscalationEvents(int $limit = 50): Collection
    {
        return EscalationEvent::with(['fromAnalyst', 'toAnalyst'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
