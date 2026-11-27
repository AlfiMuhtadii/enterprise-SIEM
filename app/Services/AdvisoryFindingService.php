<?php

namespace App\Services;

use App\Models\AdvisoryFinding;
use App\Models\AdvisoryFindingEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdvisoryFindingService
{
    // Confidence threshold above which a finding is flagged as a promotion candidate.
    // Must still pass domain_soak_required before any actual promotion.
    private const PROMOTION_CONFIDENCE_THRESHOLD = 0.75;
    private const PROMOTION_OCCURRENCE_THRESHOLD = 3;

    public function upsertFromShadow(array $payload): AdvisoryFinding
    {
        return DB::transaction(fn () => $this->persistShadowFinding($payload));
    }

    private function persistShadowFinding(array $payload): AdvisoryFinding
    {
        $fingerprint = $payload['fingerprint'];
        $now         = now();

        $existing = AdvisoryFinding::where('fingerprint', $fingerprint)->lockForUpdate()->first();

        if ($existing) {
            $existing->increment('occurrence_count');
            $existing->last_seen_at = $now;
            // Refresh promotion candidacy on each occurrence
            if ($existing->reviewBlocksPromotion()) {
                $existing->promotion_candidate = false;
            } elseif (!$existing->promotion_candidate && !$existing->isRehearsal() && !$existing->hasIncompleteLineage()) {
                $existing->promotion_candidate = $this->isPromotionCandidate(
                    (float) ($payload['confidence'] ?? 0),
                    $existing->occurrence_count,
                );
            }
            $existing->save();

            $this->appendEvent($existing->finding_id, 'occurrence_incremented', null, [
                'occurrence_count' => $existing->occurrence_count,
                'last_seen_at'     => $now->toIso8601String(),
            ], $existing->tenant_id);

            return $existing;
        }

        $candidate = ($payload['evidence']['shadow_evidence_origin'] ?? null) !== 'rehearsal'
            && ($payload['evidence']['evidence_lineage_complete'] ?? null) !== false
            && ($payload['evidence']['evidence_lineage_truncated'] ?? null) !== true
            && $this->isPromotionCandidate((float) ($payload['confidence'] ?? 0), 1);

        $finding = AdvisoryFinding::create([
            'finding_id'          => $payload['finding_id'] ?? Str::uuid()->toString(),
            'rule_id'             => $payload['rule_id'],
            'domain'              => $payload['domain'],
            'source_topic'        => $payload['source_topic'],
            'alert_type'          => $payload['alert_type'],
            'severity'            => $payload['severity'] ?? 'medium',
            'confidence'          => $payload['confidence'] ?? null,
            'actor_key'           => $payload['actor_key'] ?? null,
            'tenant_id'           => $payload['tenant_id'] ?? null,
            'evidence'            => $payload['evidence'] ?? [],
            'source_event_ids'    => $payload['source_event_ids'] ?? [],
            'promotion_candidate' => $candidate,
            'promotion_blocker'   => $this->promotionBlocker($payload['domain'], (float) ($payload['confidence'] ?? 0)),
            'status'              => 'new',
            'fingerprint'         => $fingerprint,
            'occurrence_count'    => 1,
            'first_seen_at'       => $now,
            'last_seen_at'        => $now,
        ]);

        $this->appendEvent($finding->finding_id, 'finding_created', null, [
            'rule_id'    => $finding->rule_id,
            'domain'     => $finding->domain,
            'severity'   => $finding->severity,
            'confidence' => $finding->confidence,
        ], $finding->tenant_id);

        return $finding;
    }

    public function review(AdvisoryFinding $finding, int $analystId, string $note = '', ?string $outcome = null): AdvisoryFinding
    {
        if ($outcome !== null && !isset(AdvisoryFinding::REVIEW_OUTCOMES[$outcome])) {
            throw ValidationException::withMessages(['review_outcome' => 'Invalid review outcome.']);
        }
        if ($outcome !== null && trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'A note is required for a classified review.']);
        }

        return DB::transaction(function () use ($finding, $analystId, $note, $outcome): AdvisoryFinding {
            $locked = AdvisoryFinding::whereKey($finding->getKey())->lockForUpdate()->firstOrFail();
            $previous = $locked->review_outcome;
            if ($outcome !== null) {
                $locked->review_outcome = $outcome;
            }
            if ($locked->reviewBlocksPromotion()) {
                $locked->promotion_candidate = false;
            }
            $this->recordAction($locked, 'reviewed', 'reviewed', $analystId, $note, [
                'review_outcome' => $locked->review_outcome,
                'previous_review_outcome' => $previous,
            ]);
            return $locked;
        });
    }

    public function dismiss(AdvisoryFinding $finding, int $analystId, string $note = ''): AdvisoryFinding
    {
        return DB::transaction(function () use ($finding, $analystId, $note): AdvisoryFinding {
            $locked = AdvisoryFinding::whereKey($finding->getKey())->lockForUpdate()->firstOrFail();
            $this->recordAction($locked, 'dismissed', 'dismissed', $analystId, $note);
            return $locked;
        });
    }

    public function nominate(AdvisoryFinding $finding, int $analystId, string $note = ''): AdvisoryFinding
    {
        return DB::transaction(function () use ($finding, $analystId, $note): AdvisoryFinding {
            $locked = AdvisoryFinding::whereKey($finding->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isRehearsal()) {
                throw ValidationException::withMessages(['action' => 'Rehearsal findings cannot be nominated for promotion.']);
            }
            if ($locked->hasIncompleteLineage()) {
                throw ValidationException::withMessages(['action' => 'Findings with incomplete event lineage cannot be nominated for promotion.']);
            }
            if ($locked->reviewBlocksPromotion()) {
                throw ValidationException::withMessages(['action' => 'False-positive and inconclusive findings cannot be nominated for promotion.']);
            }
            $this->recordAction($locked, 'nominated', 'nominated_for_promotion', $analystId, $note, [
                'promotion_blocker' => $locked->promotion_blocker,
            ]);
            return $locked;
        });
    }

    private function recordAction(AdvisoryFinding $finding, string $status, string $event, int $analystId, string $note, array $metadata = []): void
    {
        $finding->status      = $status;
        $finding->reviewed_by = $analystId;
        $finding->reviewed_at = now();
        if ($note !== '') {
            $finding->review_note = $note;
        }
        $finding->save();

        $this->appendEvent($finding->finding_id, $event, $analystId, ['note' => $note] + $metadata, $finding->tenant_id);
    }

    public function getSummary(?string $tenantId = null): array
    {
        $base = AdvisoryFinding::query();
        if ($tenantId !== null) {
            $base->where('tenant_id', $tenantId);
        }

        return [
            'total'               => (clone $base)->count(),
            'new'                 => (clone $base)->where('status', 'new')->count(),
            'reviewed'            => (clone $base)->where('status', 'reviewed')->count(),
            'dismissed'           => (clone $base)->where('status', 'dismissed')->count(),
            'nominated'           => (clone $base)->where('status', 'nominated')->count(),
            'promotion_candidates' => (clone $base)->reviewPromotionCandidate()->count(),
            'by_domain'           => (clone $base)->selectRaw('domain, count(*) as cnt')
                ->groupBy('domain')->pluck('cnt', 'domain')->toArray(),
            'by_severity'         => (clone $base)->selectRaw('severity, count(*) as cnt')
                ->groupBy('severity')->pluck('cnt', 'severity')->toArray(),
        ];
    }

    public function getFindings(array $filters = [])
    {
        $q = AdvisoryFinding::query()->orderByDesc('last_seen_at');

        if (!empty($filters['tenant_id'])) {
            $q->where('tenant_id', $filters['tenant_id']);
        }
        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (!empty($filters['domain'])) {
            $q->where('domain', $filters['domain']);
        }
        if (!empty($filters['severity'])) {
            $q->where('severity', $filters['severity']);
        }
        if (isset($filters['promotion_candidate'])) {
            $q->reviewPromotionCandidate((bool) $filters['promotion_candidate']);
        }
        if (!empty($filters['rule_id'])) {
            $q->where('rule_id', 'like', '%' . $filters['rule_id'] . '%');
        }

        return $q->paginate(50);
    }

    public function getReviewOutcomes(string $tenantId, string $ruleId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $counts = AdvisoryFinding::withoutRehearsal()
            ->where('tenant_id', $tenantId)->where('rule_id', $ruleId)
            ->where('first_seen_at', '>=', $start)->where('first_seen_at', '<', $end)
            ->selectRaw("coalesce(review_outcome, 'unclassified') as outcome, count(*) as total")
            ->groupBy('outcome')->pluck('total', 'outcome')->all();
        $counts = array_map('intval', $counts + array_fill_keys([...array_keys(AdvisoryFinding::REVIEW_OUTCOMES), 'unclassified'], 0));
        $definitive = $counts['confirmed_detection'] + $counts['false_positive'];
        $total = array_sum($counts);

        return [
            'tenant_id' => $tenantId,
            'rule_id' => $ruleId,
            'first_seen_start' => $start->toIso8601ZuluString(),
            'first_seen_end_exclusive' => $end->toIso8601ZuluString(),
            'findings' => $total,
            'outcomes' => $counts,
            'definitive_review_coverage' => $total > 0 ? round($definitive / $total, 4) : null,
            'false_positive_share_of_definitive_reviews' => $definitive > 0 ? round($counts['false_positive'] / $definitive, 4) : null,
            'evidence_basis' => 'analyst_verdicts_on_non_rehearsal_findings',
            'promotion_evidence_verified' => false,
        ];
    }

    private function isPromotionCandidate(float $confidence, int $occurrences): bool
    {
        return $confidence >= self::PROMOTION_CONFIDENCE_THRESHOLD
            && $occurrences >= self::PROMOTION_OCCURRENCE_THRESHOLD;
    }

    private function promotionBlocker(string $domain, float $confidence): string
    {
        $reasons = ['domain_soak_required: no 6h soak PASS for domain=' . $domain];
        if ($confidence < self::PROMOTION_CONFIDENCE_THRESHOLD) {
            $reasons[] = 'low_confidence: ' . number_format($confidence, 2) . ' < ' . self::PROMOTION_CONFIDENCE_THRESHOLD;
        }
        return implode('; ', $reasons);
    }

    private function appendEvent(string $findingId, string $eventType, ?int $analystId, array $metadata = [], ?string $tenantId = null): void
    {
        AdvisoryFindingEvent::create([
            'event_id'   => Str::uuid()->toString(),
            'finding_id' => $findingId,
            'event_type' => $eventType,
            'analyst_id' => $analystId,
            'tenant_id'  => $tenantId,
            'metadata'   => $metadata,
            'created_at' => now(),
        ]);
    }
}
