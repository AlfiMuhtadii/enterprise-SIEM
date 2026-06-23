<?php

namespace App\Services;

use App\Models\DlqRecord;
use App\Models\DlqNormalizationEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * DLQ normalization review service.
 *
 * Manages analyst actions on normalization DLQ records:
 * - Review (acknowledge, no action)
 * - Ignore (dismiss as noise)
 * - Request replay (mark for replay via Artisan dlq:replay command)
 *
 * Replay is NOT executed here — it runs via Artisan to keep the safe path
 * explicit and engineer-triggered. No automatic replay. No HTTP replay triggers.
 * Replay only allowed when raw_payload is present (poison records cannot be replayed).
 */
class DlqReviewService
{
    // replay_requested can only be set from new|reviewed|replay_failed
    private const REPLAYABLE_FROM = ['new', 'reviewed', 'replay_failed'];

    public function upsertFromConsumer(array $data): string
    {
        $now = now();
        $existing = DlqRecord::where('fingerprint', $data['fingerprint'])->first();

        if ($existing) {
            $existing->increment('occurrence_count');
            $existing->update(['last_seen_at' => $now]);
            $this->appendEvent($existing->record_id, 'occurrence_incremented', null, [
                'occurrence_count' => $existing->occurrence_count + 1,
            ], $existing->tenant_id);
            return 'updated';
        }

        $record = DlqRecord::create(array_merge($data, [
            'record_id'    => 'dlq-' . Str::uuid(),
            'status'       => 'new',
            'replayable'   => $data['replayable'] ?? false,
            'error_reason' => $data['error_reason'] ?? null,
            'first_seen_at' => $now,
            'last_seen_at'  => $now,
        ]));

        $this->appendEvent($record->record_id, 'record_created', null, [
            'dlq_event_type' => $data['dlq_event_type'] ?? 'unknown',
            'source_topic'   => $data['source_topic'] ?? null,
        ], $record->tenant_id);

        return 'created';
    }

    public function review(DlqRecord $record, User $analyst, ?string $note = null): DlqRecord
    {
        if (!$record->isActionable()) {
            throw new \InvalidArgumentException("Record {$record->record_id} is not actionable (status: {$record->status}).");
        }

        $record->update([
            'status'      => 'reviewed',
            'reviewed_by' => $analyst->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->appendEvent($record->record_id, 'reviewed', $analyst->id, [
            'note' => $note,
        ], $record->tenant_id);

        return $record->fresh();
    }

    public function ignore(DlqRecord $record, User $analyst, ?string $note = null): DlqRecord
    {
        if (!$record->isActionable()) {
            throw new \InvalidArgumentException("Record {$record->record_id} is not actionable (status: {$record->status}).");
        }

        $record->update([
            'status'      => 'ignored',
            'reviewed_by' => $analyst->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->appendEvent($record->record_id, 'ignored', $analyst->id, [
            'note' => $note,
        ], $record->tenant_id);

        return $record->fresh();
    }

    public function requestReplay(DlqRecord $record, User $analyst, ?string $note = null): DlqRecord
    {
        if (!in_array($record->status, self::REPLAYABLE_FROM)) {
            throw new \InvalidArgumentException(
                "Replay can only be requested from: " . implode(', ', self::REPLAYABLE_FROM) .
                " (current: {$record->status})."
            );
        }

        if (!$record->replayable) {
            throw new \InvalidArgumentException(
                "Record {$record->record_id} is not classified as replayable (error_type: {$record->dlq_event_type}) — structural failures cannot be retried."
            );
        }

        if ($record->raw_payload === null) {
            throw new \InvalidArgumentException(
                "Record {$record->record_id} has no raw_payload — replay data not available."
            );
        }

        $record->update([
            'status'                => 'replay_requested',
            'replay_requested_by'   => $analyst->id,
            'replay_requested_at'   => now(),
            'review_note'           => $note,
        ]);

        $this->appendEvent($record->record_id, 'replay_requested', $analyst->id, [
            'note'            => $note,
            'dlq_event_type'  => $record->dlq_event_type,
            'source_topic'    => $record->source_topic,
        ], $record->tenant_id);

        return $record->fresh();
    }

    public function markReplayed(DlqRecord $record, array $result): DlqRecord
    {
        $record->update([
            'status'       => 'replayed',
            'replayed_at'  => now(),
            'replay_result' => $result,
        ]);

        $this->appendEvent($record->record_id, 'replayed', null, ['result' => $result], $record->tenant_id);

        return $record->fresh();
    }

    public function markReplayFailed(DlqRecord $record, string $error): DlqRecord
    {
        $record->update([
            'status'        => 'replay_failed',
            'replay_result' => ['error' => $error, 'failed_at' => now()->toIso8601String()],
        ]);

        $this->appendEvent($record->record_id, 'replay_failed', null, ['error' => $error], $record->tenant_id);

        return $record->fresh();
    }

    public function getRecords(array $filters = [], int $perPage = 30): LengthAwarePaginator
    {
        $query = DlqRecord::orderByDesc('last_seen_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['dlq_event_type'])) {
            $query->where('dlq_event_type', $filters['dlq_event_type']);
        }
        if (!empty($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }
        if (!empty($filters['source_topic'])) {
            $query->where('source_topic', $filters['source_topic']);
        }
        if (!empty($filters['replayable'])) {
            $query->where('replayable', true)
                  ->whereNotNull('raw_payload')
                  ->whereNotIn('status', ['ignored', 'replayed']);
        }

        if (!empty($filters['replayable_class'])) {
            $query->where('replayable', true);
        }

        return $query->paginate($perPage);
    }

    public function getSummary(?string $tenantId = null): array
    {
        $base = DlqRecord::query();
        if ($tenantId !== null) {
            $base->where('tenant_id', $tenantId);
        }

        $counts = (clone $base)->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $byType = (clone $base)->selectRaw('dlq_event_type, count(*) as cnt')
            ->groupBy('dlq_event_type')
            ->pluck('cnt', 'dlq_event_type')
            ->toArray();

        $bySourceTopic = (clone $base)->selectRaw('source_topic, count(*) as cnt')
            ->groupBy('source_topic')
            ->pluck('cnt', 'source_topic')
            ->toArray();

        $replayableBase = (clone $base)->where('replayable', true);

        return [
            'total'                => array_sum($counts),
            'new'                  => $counts['new'] ?? 0,
            'reviewed'             => $counts['reviewed'] ?? 0,
            'ignored'              => $counts['ignored'] ?? 0,
            'replay_requested'     => $counts['replay_requested'] ?? 0,
            'replayed'             => $counts['replayed'] ?? 0,
            'replay_failed'        => $counts['replay_failed'] ?? 0,
            'replayable'           => (clone $replayableBase)
                                         ->whereNotNull('raw_payload')
                                         ->whereNotIn('status', ['ignored', 'replayed'])
                                         ->count(),
            'replayable_classified' => (clone $replayableBase)->count(),
            'by_type'              => $byType,
            'by_source_topic'      => $bySourceTopic,
        ];
    }

    private function appendEvent(string $recordId, string $eventType, ?int $analystId, array $metadata = [], ?string $tenantId = null): void
    {
        DlqNormalizationEvent::create([
            'event_id'   => 'dlqev-' . Str::uuid(),
            'record_id'  => $recordId,
            'event_type' => $eventType,
            'analyst_id' => $analystId,
            'tenant_id'  => $tenantId,
            'metadata'   => $metadata ?: null,
        ]);
    }
}
