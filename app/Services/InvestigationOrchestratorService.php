<?php

namespace App\Services;

use App\Models\Investigation;
use App\Models\InvestigationArtifact;
use App\Models\InvestigationAssignment;
use App\Models\InvestigationEvent;
use App\Models\InvestigationNote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Investigation workflow orchestration — analyst triage and coordination only.
 *
 * This service manages investigation lifecycle, assignment history, notes,
 * artifacts, and append-only event audit trails.
 *
 * IMPORTANT: This service MUST NOT trigger containment, quarantine, isolation,
 * or any automated pipeline actions. All state transitions are documentation only.
 * contained_manual state records analyst documentation — no code sends block/kill signals.
 */
class InvestigationOrchestratorService
{
    /**
     * Create a new investigation. Appends 'created' event immediately.
     */
    public function createInvestigation(array $data, int $creatorUserId): Investigation
    {
        return DB::transaction(function () use ($data, $creatorUserId) {
            $investigation = Investigation::create([
                'investigation_id' => $this->generateId(),
                'title'            => $data['title'],
                'description'      => $data['description'] ?? null,
                'state'            => 'new',
                'severity'         => $data['severity'] ?? 'medium',
                'priority'         => $data['priority'] ?? 3,
                'created_by'       => $creatorUserId,
                'trace_id'         => $data['trace_id'] ?? null,
                'alert_ids'        => !empty($data['alert_ids']) ? $data['alert_ids'] : null,
                'incident_ids'     => !empty($data['incident_ids']) ? $data['incident_ids'] : null,
                'entity_ids'       => !empty($data['entity_ids']) ? $data['entity_ids'] : null,
                'risk_score'       => $data['risk_score'] ?? null,
                'sla_breach_at'    => $data['sla_breach_at'] ?? null,
                'metadata'         => $data['metadata'] ?? null,
            ]);

            $this->appendEvent($investigation->id, 'created', $creatorUserId, [
                'severity' => $investigation->severity,
                'priority' => $investigation->priority,
            ]);

            if (!empty($data['assign_to'])) {
                $this->assignAnalyst($investigation->id, (int) $data['assign_to'], $creatorUserId);
            }

            return $investigation;
        });
    }

    /**
     * Transition investigation to a new state. Enforces valid transitions.
     * All transitions are documentation — no pipeline side effects.
     *
     * @throws \InvalidArgumentException on invalid transition
     */
    public function transitionState(
        int     $investigationId,
        string  $newState,
        int     $actorUserId,
        ?string $note = null
    ): void {
        DB::transaction(function () use ($investigationId, $newState, $actorUserId, $note) {
            $inv = Investigation::lockForUpdate()->findOrFail($investigationId);

            if (!$this->isValidTransition($inv->state, $newState)) {
                throw new \InvalidArgumentException(
                    "Invalid state transition: {$inv->state} → {$newState}"
                );
            }

            $fromState  = $inv->state;
            $timestamps = [];

            if ($newState === 'triaged')          $timestamps['triage_at']   = now()->toDateTimeString();
            if ($newState === 'resolved')          $timestamps['resolved_at'] = now()->toDateTimeString();
            if (in_array($newState, ['closed', 'false_positive'], true)) {
                $timestamps['closed_at'] = now()->toDateTimeString();
            }

            $inv->update(array_merge(['state' => $newState], $timestamps));

            $this->appendEvent(
                $investigationId, 'state_transition', $actorUserId,
                ['from_state' => $fromState, 'to_state' => $newState, 'note' => $note],
                $fromState, $newState
            );

            if ($note) {
                $this->addNote($investigationId, $actorUserId, 'general', $note);
            }
        });
    }

    /**
     * Assign an analyst. Deactivates previous assignment; preserves history.
     * Assignment history is never deleted — append-only.
     */
    public function assignAnalyst(int $investigationId, int $assigneeUserId, int $actorUserId): void
    {
        DB::transaction(function () use ($investigationId, $assigneeUserId, $actorUserId) {
            $prev = InvestigationAssignment::where('investigation_id', $investigationId)
                ->where('is_active', true)
                ->first();

            $prevUserId = $prev?->assigned_to_user_id;

            if ($prev) {
                DB::table('investigation_assignments')
                    ->where('id', $prev->id)
                    ->update(['is_active' => false, 'unassigned_at' => now()->toDateTimeString()]);
            }

            InvestigationAssignment::create([
                'investigation_id'     => $investigationId,
                'assigned_to_user_id'  => $assigneeUserId,
                'assigned_by_user_id'  => $actorUserId,
                'role'                 => 'primary',
                'assigned_at'          => now()->toDateTimeString(),
                'is_active'            => true,
            ]);

            DB::table('investigations')
                ->where('id', $investigationId)
                ->update(['assigned_to' => $assigneeUserId]);

            $this->appendEvent($investigationId, 'assignment', $actorUserId, [
                'assigned_to_user_id'  => $assigneeUserId,
                'previous_user_id'     => $prevUserId,
            ]);
        });
    }

    /**
     * Add a note. Always inserts — append-only history.
     */
    public function addNote(
        int     $investigationId,
        int     $actorUserId,
        string  $type,
        string  $body,
        ?string $traceId = null,
        bool    $pinned = false
    ): InvestigationNote {
        return DB::transaction(function () use ($investigationId, $actorUserId, $type, $body, $traceId, $pinned) {
            $note = InvestigationNote::create([
                'investigation_id' => $investigationId,
                'author_user_id'   => $actorUserId,
                'note_type'        => $type,
                'body'             => $body,
                'trace_id'         => $traceId,
                'is_pinned'        => $pinned,
            ]);

            $this->appendEvent($investigationId, 'note_added', $actorUserId, [
                'note_id'   => $note->id,
                'note_type' => $type,
            ], null, null, $traceId);

            return $note;
        });
    }

    /**
     * Add an artifact record. Reference field must NOT contain secrets/credentials.
     * Always inserts — append-only.
     */
    public function addArtifact(int $investigationId, int $actorUserId, array $data): InvestigationArtifact
    {
        return DB::transaction(function () use ($investigationId, $actorUserId, $data) {
            $artifact = InvestigationArtifact::create([
                'investigation_id' => $investigationId,
                'artifact_type'    => $data['artifact_type'],
                'title'            => $data['title'],
                'reference'        => $data['reference'] ?? null,
                'description'      => $data['description'] ?? null,
                'added_by_user_id' => $actorUserId,
            ]);

            $this->appendEvent($investigationId, 'artifact_added', $actorUserId, [
                'artifact_id'   => $artifact->id,
                'artifact_type' => $data['artifact_type'],
                'title'         => $data['title'],
            ]);

            return $artifact;
        });
    }

    /**
     * Get the full append-only event timeline for an investigation.
     */
    public function getTimeline(int $investigationId): Collection
    {
        return DB::table('investigation_events as e')
            ->leftJoin('users', 'users.id', '=', 'e.actor_user_id')
            ->select('e.*', 'users.name as actor_name')
            ->where('e.investigation_id', $investigationId)
            ->orderBy('e.occurred_at')
            ->get();
    }

    /**
     * List investigations with optional filters.
     */
    public function list(array $filters = [], int $limit = 100): Collection
    {
        $query = DB::table('investigations as i')
            ->leftJoin('users as a', 'a.id', '=', 'i.assigned_to')
            ->leftJoin('users as c', 'c.id', '=', 'i.created_by')
            ->select('i.*', 'a.name as assignee_name', 'c.name as creator_name')
            ->orderByDesc('i.created_at')
            ->limit($limit);

        if (!empty($filters['state'])) {
            $query->where('i.state', $filters['state']);
        } elseif (empty($filters['include_closed'])) {
            $query->whereNotIn('i.state', ['closed']);
        }
        if (!empty($filters['severity'])) {
            $query->where('i.severity', $filters['severity']);
        }
        if (!empty($filters['assigned_to'])) {
            $query->where('i.assigned_to', $filters['assigned_to']);
        }
        if (isset($filters['unassigned']) && $filters['unassigned']) {
            $query->whereNull('i.assigned_to');
        }

        return $query->get();
    }

    public function getStateCounts(): Collection
    {
        return DB::table('investigations')
            ->select('state', DB::raw('count(*) as count'))
            ->groupBy('state')
            ->orderByRaw("CASE state WHEN 'escalated' THEN 1 WHEN 'investigating' THEN 2 WHEN 'triaged' THEN 3 WHEN 'new' THEN 4 WHEN 'contained_manual' THEN 5 WHEN 'resolved' THEN 6 WHEN 'closed' THEN 7 ELSE 8 END")
            ->get()
            ->keyBy('state');
    }

    public function isValidTransition(string $from, string $to): bool
    {
        return in_array($to, Investigation::STATE_TRANSITIONS[$from] ?? [], true);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function appendEvent(
        int     $investigationId,
        string  $eventType,
        ?int    $actorUserId,
        array   $payload = [],
        ?string $fromState = null,
        ?string $toState = null,
        ?string $traceId = null
    ): void {
        InvestigationEvent::create([
            'investigation_id' => $investigationId,
            'event_type'       => $eventType,
            'actor_user_id'    => $actorUserId,
            'from_state'       => $fromState,
            'to_state'         => $toState,
            'payload'          => $payload ?: null,
            'trace_id'         => $traceId,
            'occurred_at'      => now()->toDateTimeString(),
        ]);
    }

    private function generateId(): string
    {
        $year  = now()->year;
        $count = DB::table('investigations')->count() + 1;
        return sprintf('INV-%d-%05d', $year, $count);
    }
}
