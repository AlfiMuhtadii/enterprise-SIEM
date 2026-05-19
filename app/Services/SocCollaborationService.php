<?php

namespace App\Services;

use App\Models\AnalystHandoff;
use App\Models\EscalationEvent;
use App\Models\Investigation;
use App\Models\InvestigationCollaborator;
use App\Models\InvestigationEvent;
use App\Models\InvestigationWatcher;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SOC Collaboration Service — analyst workflow governance.
 *
 * Analyst-driven collaborative workflows only. No autonomous SOC operations.
 * All collaboration is explicit, auditable, append-only, and operator-driven.
 * No hidden workflow automation. No analyst impersonation.
 */
class SocCollaborationService
{
    // -----------------------------------------------------------------------
    // Collaborator management — append-only history
    // -----------------------------------------------------------------------

    /**
     * Add an analyst as a collaborator to an investigation.
     * Append-only — explicit actor attribution on every call.
     */
    public function addCollaborator(
        string  $investigationId,
        int     $analystId,
        string  $role,
        User    $addedBy,
        ?string $note = null
    ): InvestigationCollaborator {
        if (!in_array($role, InvestigationCollaborator::ROLES, true)) {
            throw new \InvalidArgumentException("Invalid collaborator role: '{$role}'.");
        }

        $collab = InvestigationCollaborator::create([
            'collab_id'        => InvestigationCollaborator::generateCollabId(),
            'investigation_id' => $investigationId,
            'analyst_id'       => $analystId,
            'role'             => $role,
            'added_by'         => $addedBy->id,
            'active'           => true,
            'note'             => $note ? substr($note, 0, 500) : null,
            'trace_id'         => 'soc-' . Str::uuid(),
        ]);

        $this->appendInvestigationEvent(
            $investigationId, 'collaborator_added', $addedBy,
            ['analyst_id' => $analystId, 'role' => $role]
        );

        return $collab;
    }

    /**
     * Archive a collaborator (append-only — never deletes, records active=false).
     */
    public function archiveCollaborator(string $collabId, User $archivedBy): InvestigationCollaborator
    {
        $collab = InvestigationCollaborator::where('collab_id', $collabId)->firstOrFail();

        // Create new archive record — never mutate the original row
        return InvestigationCollaborator::create([
            'collab_id'        => InvestigationCollaborator::generateCollabId(),
            'investigation_id' => $collab->investigation_id,
            'analyst_id'       => $collab->analyst_id,
            'role'             => $collab->role,
            'added_by'         => $archivedBy->id,
            'active'           => false,
            'note'             => 'Archived by ' . $archivedBy->name,
            'trace_id'         => 'soc-' . Str::uuid(),
        ]);
    }

    public function addWatcher(
        string  $investigationId,
        int     $analystId,
        User    $addedBy,
        ?string $watchReason = null
    ): InvestigationWatcher {
        return InvestigationWatcher::create([
            'watcher_id'       => InvestigationWatcher::generateWatcherId(),
            'investigation_id' => $investigationId,
            'analyst_id'       => $analystId,
            'watch_reason'     => $watchReason ? substr($watchReason, 0, 500) : null,
            'added_by'         => $addedBy->id,
            'trace_id'         => 'soc-' . Str::uuid(),
        ]);
    }

    public function getCollaborators(string $investigationId): Collection
    {
        return InvestigationCollaborator::where('investigation_id', $investigationId)
            ->where('active', true)
            ->with('analyst')
            ->orderByDesc('id')
            ->get();
    }

    public function getWatchers(string $investigationId): Collection
    {
        return InvestigationWatcher::where('investigation_id', $investigationId)
            ->with('analyst')
            ->orderByDesc('id')
            ->get();
    }

    // -----------------------------------------------------------------------
    // Watchlist management
    // -----------------------------------------------------------------------

    public function createWatchlist(
        User    $analyst,
        string  $watchType,
        string  $watchKey,
        ?string $watchReason,
        ?string $expiresAt = null
    ): Watchlist {
        if (!in_array($watchType, Watchlist::WATCH_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid watch type: '{$watchType}'.");
        }

        $wl = Watchlist::create([
            'watchlist_id' => Watchlist::generateWatchlistId(),
            'analyst_id'   => $analyst->id,
            'watch_type'   => $watchType,
            'watch_key'    => substr($watchKey, 0, 512),
            'watch_reason' => $watchReason ? substr($watchReason, 0, 1000) : null,
            'active'       => true,
            'expires_at'   => $expiresAt,
            'trace_id'     => 'soc-' . Str::uuid(),
        ]);

        WatchlistEvent::create([
            'event_id'     => WatchlistEvent::generateEventId(),
            'watchlist_id' => $wl->watchlist_id,
            'event_type'   => WatchlistEvent::TYPE_CREATED,
            'analyst_id'   => $analyst->id,
            'details'      => ['watch_type' => $watchType, 'watch_key' => $watchKey],
            'trace_id'     => 'soc-' . Str::uuid(),
        ]);

        return $wl;
    }

    public function deactivateWatchlist(string $watchlistId, User $analyst): Watchlist
    {
        $wl = Watchlist::where('watchlist_id', $watchlistId)->firstOrFail();
        $wl->update(['active' => false]);

        WatchlistEvent::create([
            'event_id'     => WatchlistEvent::generateEventId(),
            'watchlist_id' => $watchlistId,
            'event_type'   => WatchlistEvent::TYPE_DEACTIVATED,
            'analyst_id'   => $analyst->id,
            'details'      => ['deactivated_by' => $analyst->name],
            'trace_id'     => 'soc-' . Str::uuid(),
        ]);

        return $wl->fresh();
    }

    public function processExpiredWatchlists(): int
    {
        $expired = Watchlist::where('active', true)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $wl) {
            $wl->update(['active' => false]);
            WatchlistEvent::create([
                'event_id'     => WatchlistEvent::generateEventId(),
                'watchlist_id' => $wl->watchlist_id,
                'event_type'   => WatchlistEvent::TYPE_EXPIRED,
                'analyst_id'   => null,
                'details'      => ['expired_at' => now()->toIso8601String()],
                'trace_id'     => 'soc-' . Str::uuid(),
            ]);
        }

        return $expired->count();
    }

    public function getActiveWatchlists(int $analystId): Collection
    {
        return Watchlist::where('analyst_id', $analystId)
            ->where('active', true)
            ->orderByDesc('created_at')
            ->get();
    }

    public function getAllWatchlists(?string $watchType = null): Collection
    {
        $q = Watchlist::with('analyst')->orderByDesc('created_at');
        if ($watchType) $q->where('watch_type', $watchType);
        return $q->get();
    }

    // -----------------------------------------------------------------------
    // Shift handoff — append-only
    // -----------------------------------------------------------------------

    public function createHandoff(
        User    $fromAnalyst,
        ?User   $toAnalyst,
        string  $shiftSummary,
        ?string $notes = null
    ): AnalystHandoff {
        $unresolvedCount = Investigation::whereNotIn('state', ['resolved', 'closed', 'false_positive'])
            ->where(function ($q) use ($fromAnalyst) {
                $q->where('assigned_to', $fromAnalyst->id)
                  ->orWhere('created_by', $fromAnalyst->id);
            })
            ->count();

        $pendingEscalations = EscalationEvent::where('to_analyst_id', $fromAnalyst->id)
            ->where('event_type', EscalationEvent::EVENT_CREATED)
            ->whereNull('acknowledged_at')
            ->count();

        $activeInvestigations = Investigation::whereNotIn('state', ['resolved', 'closed', 'false_positive'])
            ->where(function ($q) use ($fromAnalyst) {
                $q->where('assigned_to', $fromAnalyst->id)
                  ->orWhere('created_by', $fromAnalyst->id);
            })
            ->pluck('investigation_id')
            ->toArray();

        return AnalystHandoff::create([
            'handoff_id'               => AnalystHandoff::generateHandoffId(),
            'from_analyst_id'          => $fromAnalyst->id,
            'to_analyst_id'            => $toAnalyst?->id,
            'shift_summary'            => substr($shiftSummary, 0, 5000),
            'unresolved_count'         => $unresolvedCount,
            'pending_escalation_count' => $pendingEscalations,
            'notes'                    => $notes ? substr($notes, 0, 5000) : null,
            'active_investigations'    => $activeInvestigations,
            'trace_id'                 => 'soc-' . Str::uuid(),
        ]);
    }

    public function getRecentHandoffs(int $limit = 20): Collection
    {
        return AnalystHandoff::with(['fromAnalyst', 'toAnalyst'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    // -----------------------------------------------------------------------
    // Investigation sharing — explicit, auditable
    // -----------------------------------------------------------------------

    /**
     * Share an investigation with another analyst.
     * Recorded as a collaborator addition with role=reviewer.
     * No unrestricted sharing — requires explicit actor attribution.
     */
    public function shareWithAnalyst(
        string  $investigationId,
        int     $targetAnalystId,
        User    $sharedBy,
        ?string $shareNote = null
    ): InvestigationCollaborator {
        return $this->addCollaborator(
            $investigationId,
            $targetAnalystId,
            InvestigationCollaborator::ROLE_REVIEWER,
            $sharedBy,
            $shareNote ?? 'Shared investigation access'
        );
    }

    // -----------------------------------------------------------------------
    // Query helpers
    // -----------------------------------------------------------------------

    public function getAnalystWorkQueue(int $analystId): array
    {
        $investigations = Investigation::whereNotIn('state', ['resolved', 'closed', 'false_positive'])
            ->where('assigned_to', $analystId)
            ->with(['assignedAnalyst'])
            ->orderByDesc('created_at')
            ->get();

        $collaborating = InvestigationCollaborator::where('analyst_id', $analystId)
            ->where('active', true)
            ->pluck('investigation_id');

        $watchlists = $this->getActiveWatchlists($analystId);

        return [
            'assigned_investigations'  => $investigations,
            'collaborating_on'         => $collaborating->count(),
            'watchlist_count'          => $watchlists->count(),
        ];
    }

    public function getSharedInvestigations(int $analystId): Collection
    {
        $investigationIds = InvestigationCollaborator::where('analyst_id', $analystId)
            ->where('active', true)
            ->where('role', InvestigationCollaborator::ROLE_REVIEWER)
            ->pluck('investigation_id');

        return Investigation::whereIn('investigation_id', $investigationIds)
            ->orderByDesc('created_at')
            ->get();
    }

    public function getCollaborationTimeline(string $investigationId): Collection
    {
        $collabs  = InvestigationCollaborator::where('investigation_id', $investigationId)->get();
        $watchers = InvestigationWatcher::where('investigation_id', $investigationId)->get();
        $escEvents= EscalationEvent::where('investigation_id', $investigationId)->get();

        return collect([
            ...$collabs->map(fn ($c) => ['type' => 'collaborator', 'data' => $c, 'at' => $c->created_at]),
            ...$watchers->map(fn ($w) => ['type' => 'watcher', 'data' => $w, 'at' => $w->created_at]),
            ...$escEvents->map(fn ($e) => ['type' => 'escalation', 'data' => $e, 'at' => $e->created_at]),
        ])->sortByDesc('at')->values();
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function appendInvestigationEvent(string $investigationId, string $eventType, User $actor, array $details = []): void
    {
        $inv = Investigation::where('investigation_id', $investigationId)->first();
        if (!$inv) return;

        InvestigationEvent::create([
            'investigation_id' => $inv->id,
            'event_type'       => $eventType,
            'actor_user_id'    => $actor->id,
            'payload'          => $details,
            'occurred_at'      => now(),
            'trace_id'         => 'soc-' . Str::uuid(),
        ]);
    }
}
