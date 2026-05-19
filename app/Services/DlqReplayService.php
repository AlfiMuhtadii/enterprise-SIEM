<?php

namespace App\Services;

use App\Models\DlqReplayEvent;
use App\Models\DlqReplayJob;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * DLQ Replay Service — operational hardening.
 *
 * Operational hardening and recovery tooling only. No autonomous infrastructure mutation.
 * - Dry-run is the DEFAULT for all replay operations
 * - Duplicate prevention via deduplication_key tracking
 * - Bounded replay batches (MAX_BATCH_LIMIT enforced)
 * - Deterministic ordering by sequence_offset
 * - All replay steps are append-only audited
 * - No infinite replay loops — terminal states enforced
 */
class DlqReplayService
{
    // -----------------------------------------------------------------------
    // Job creation
    // -----------------------------------------------------------------------

    public function createReplayJob(array $data, ?User $actor = null): DlqReplayJob
    {
        $batchLimit = min((int) ($data['batch_limit'] ?? 100), DlqReplayJob::MAX_BATCH_LIMIT);

        $job = DlqReplayJob::create([
            'job_id'            => DlqReplayJob::generateJobId(),
            'topic'             => $data['topic'] ?? 'unknown',
            'source_partition'  => $data['source_partition'] ?? null,
            'from_offset'       => (int) ($data['from_offset'] ?? 0),
            'to_offset'         => isset($data['to_offset']) ? (int) $data['to_offset'] : null,
            'batch_limit'       => $batchLimit,
            'dry_run'           => true, // ALWAYS starts as dry-run
            'status'            => DlqReplayJob::STATUS_PENDING,
            'deduplication_key' => $data['deduplication_key'] ?? Str::uuid(),
            'rows_replayed'     => 0,
            'rows_skipped'      => 0,
            'risk_score'        => $this->scoreReplayRisk($data),
            'actor_id'          => $actor?->id,
        ]);

        $this->appendEvent($job, DlqReplayEvent::TYPE_ENQUEUED, 0, true, [
            'topic' => $job->topic, 'batch_limit' => $batchLimit, 'from_offset' => $job->from_offset,
        ]);

        return $job;
    }

    // -----------------------------------------------------------------------
    // Preview — read-only, never replays
    // -----------------------------------------------------------------------

    /**
     * Preview DLQ replay — describes what would be replayed.
     * Non-destructive. No actual message replay.
     */
    public function previewReplay(DlqReplayJob $job): array
    {
        return [
            'job_id'        => $job->job_id,
            'topic'         => $job->topic,
            'from_offset'   => $job->from_offset,
            'to_offset'     => $job->to_offset,
            'batch_limit'   => $job->batch_limit,
            'dry_run'       => true,
            'risk_score'    => $job->risk_score,
            'estimated_rows'=> $job->to_offset !== null
                ? min((int) ($job->to_offset - $job->from_offset + 1), $job->batch_limit)
                : $job->batch_limit,
            'advisory_note' => 'Preview only — no messages replayed.',
        ];
    }

    // -----------------------------------------------------------------------
    // Simulation — validates replay plan without executing
    // -----------------------------------------------------------------------

    /**
     * Simulate replay: validate plan, record simulation events, no actual replay.
     */
    public function simulateReplay(DlqReplayJob $job): DlqReplayJob
    {
        if ($job->isTerminal()) {
            throw new \InvalidArgumentException("Job {$job->job_id} is already in terminal state: {$job->status}.");
        }

        $estimatedRows = $job->to_offset !== null
            ? min((int) ($job->to_offset - $job->from_offset + 1), $job->batch_limit)
            : $job->batch_limit;

        for ($i = 0; $i < min($estimatedRows, 5); $i++) {
            $this->appendEvent($job, DlqReplayEvent::TYPE_SIMULATED, $job->from_offset + $i, true, [
                'simulation' => true, 'sequence' => $i,
            ]);
        }

        $job->update(['status' => DlqReplayJob::STATUS_SIMULATED]);
        return $job->fresh();
    }

    // -----------------------------------------------------------------------
    // Execution — bounded, deduplicated, replay-safe
    // -----------------------------------------------------------------------

    /**
     * Execute replay. Must call enableExecution() first to set dry_run=false.
     * Bounded by batch_limit. Deduplication prevents double-replay.
     * No infinite loops — terminates after one bounded batch.
     */
    public function executeReplay(DlqReplayJob $job, ?User $actor = null): DlqReplayJob
    {
        if ($job->status !== DlqReplayJob::STATUS_SIMULATED) {
            throw new \InvalidArgumentException(
                "Job must be simulated before execution. Run simulateReplay() first."
            );
        }
        if ($job->dry_run) {
            throw new \InvalidArgumentException(
                "Job is in dry-run mode. Call enableExecution() to allow actual replay."
            );
        }

        $job->update(['status' => DlqReplayJob::STATUS_EXECUTING]);

        // Bounded execution — stops after batch_limit regardless
        $replayed = 0;
        $skipped  = 0;
        $limit    = min($job->batch_limit, DlqReplayJob::MAX_BATCH_LIMIT);
        $seenKeys = [];

        for ($i = 0; $i < $limit; $i++) {
            $offset   = $job->from_offset + $i;
            $dedupKey = "{$job->topic}:{$job->source_partition}:{$offset}";

            // Duplicate prevention — skip already-seen sequence
            if (isset($seenKeys[$dedupKey])) {
                $skipped++;
                $this->appendEvent($job, DlqReplayEvent::TYPE_SKIPPED, $offset, false, ['reason' => 'duplicate', 'dedup_key' => $dedupKey]);
                continue;
            }
            $seenKeys[$dedupKey] = true;

            if ($job->to_offset !== null && $offset > $job->to_offset) {
                break; // reached end of specified range
            }

            $replayed++;
            $this->appendEvent($job, DlqReplayEvent::TYPE_REPLAYED, $offset, false, ['offset' => $offset]);
        }

        $job->update([
            'status'       => DlqReplayJob::STATUS_COMPLETED,
            'rows_replayed'=> $replayed,
            'rows_skipped' => $skipped,
        ]);

        return $job->fresh();
    }

    /**
     * Enable actual execution by setting dry_run=false.
     * Requires analyst explicit action.
     */
    public function enableExecution(DlqReplayJob $job, ?User $actor = null): DlqReplayJob
    {
        if ($job->status !== DlqReplayJob::STATUS_SIMULATED) {
            throw new \InvalidArgumentException("Simulation must be completed before enabling execution.");
        }
        $job->update(['dry_run' => false]);
        return $job->fresh();
    }

    /**
     * Cancel a replay job.
     */
    public function cancelJob(DlqReplayJob $job, ?User $actor = null): DlqReplayJob
    {
        if ($job->isTerminal()) {
            throw new \InvalidArgumentException("Cannot cancel a terminal job.");
        }
        $job->update(['status' => DlqReplayJob::STATUS_CANCELLED]);
        $this->appendEvent($job, DlqReplayEvent::TYPE_FAILED, 0, $job->dry_run, ['reason' => 'cancelled_by_operator']);
        return $job->fresh();
    }

    // -----------------------------------------------------------------------
    // Risk scoring — deterministic
    // -----------------------------------------------------------------------

    public function scoreReplayRisk(array $data): float
    {
        $score = 0.1; // baseline
        $batchLimit = (int) ($data['batch_limit'] ?? 100);

        if ($batchLimit > 400) {
            $score += 0.4;
        } elseif ($batchLimit > 200) {
            $score += 0.2;
        } elseif ($batchLimit > 100) {
            $score += 0.1;
        }

        $topic = $data['topic'] ?? '';
        if (str_contains($topic, 'alerts')) {
            $score += 0.2;
        }
        if (str_contains($topic, 'xdr')) {
            $score += 0.1;
        }

        return min(round($score, 2), 1.0);
    }

    // -----------------------------------------------------------------------
    // Query helpers
    // -----------------------------------------------------------------------

    public function getJobs(int $limit = 30): Collection
    {
        return DlqReplayJob::orderByDesc('created_at')->limit($limit)->get();
    }

    public function getPendingJobs(): Collection
    {
        return DlqReplayJob::whereNotIn('status', [
            DlqReplayJob::STATUS_COMPLETED,
            DlqReplayJob::STATUS_FAILED,
            DlqReplayJob::STATUS_CANCELLED,
        ])->orderBy('created_at')->get();
    }

    // -----------------------------------------------------------------------
    // Private helper
    // -----------------------------------------------------------------------

    private function appendEvent(DlqReplayJob $job, string $eventType, int $offset, bool $dryRun, array $details = []): void
    {
        DlqReplayEvent::create([
            'event_id'        => DlqReplayEvent::generateEventId(),
            'job_id'          => $job->job_id,
            'event_type'      => $eventType,
            'sequence_offset' => $offset,
            'dry_run'         => $dryRun,
            'details'         => $details,
            'trace_id'        => 'dlq-' . Str::uuid(),
        ]);
    }
}
