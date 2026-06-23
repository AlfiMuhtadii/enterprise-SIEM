<?php

namespace App\Services;

use App\Models\AdvisoryFinding;
use App\Models\AdvisoryFindingEvent;
use Illuminate\Support\Str;

class AdvisoryFindingService
{
    // Confidence threshold above which a finding is flagged as a promotion candidate.
    // Must still pass domain_soak_required before any actual promotion.
    private const PROMOTION_CONFIDENCE_THRESHOLD = 0.75;
    private const PROMOTION_OCCURRENCE_THRESHOLD = 3;

    public function upsertFromShadow(array $payload): AdvisoryFinding
    {
        $fingerprint = $payload['fingerprint'];
        $now         = now();

        $existing = AdvisoryFinding::where('fingerprint', $fingerprint)->first();

        if ($existing) {
            $existing->increment('occurrence_count');
            $existing->last_seen_at = $now;
            // Refresh promotion candidacy on each occurrence
            if (!$existing->promotion_candidate) {
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

        $candidate = $this->isPromotionCandidate((float) ($payload['confidence'] ?? 0), 1);

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

    public function review(AdvisoryFinding $finding, int $analystId, string $note = ''): AdvisoryFinding
    {
        $finding->status      = 'reviewed';
        $finding->reviewed_by = $analystId;
        $finding->reviewed_at = now();
        if ($note !== '') {
            $finding->review_note = $note;
        }
        $finding->save();

        $this->appendEvent($finding->finding_id, 'reviewed', $analystId, [
            'note' => $note,
        ], $finding->tenant_id);

        return $finding;
    }

    public function dismiss(AdvisoryFinding $finding, int $analystId, string $note = ''): AdvisoryFinding
    {
        $finding->status      = 'dismissed';
        $finding->reviewed_by = $analystId;
        $finding->reviewed_at = now();
        if ($note !== '') {
            $finding->review_note = $note;
        }
        $finding->save();

        $this->appendEvent($finding->finding_id, 'dismissed', $analystId, [
            'note' => $note,
        ], $finding->tenant_id);

        return $finding;
    }

    public function nominate(AdvisoryFinding $finding, int $analystId, string $note = ''): AdvisoryFinding
    {
        $finding->status      = 'nominated';
        $finding->reviewed_by = $analystId;
        $finding->reviewed_at = now();
        if ($note !== '') {
            $finding->review_note = $note;
        }
        $finding->save();

        $this->appendEvent($finding->finding_id, 'nominated_for_promotion', $analystId, [
            'note'              => $note,
            'promotion_blocker' => $finding->promotion_blocker,
        ], $finding->tenant_id);

        return $finding;
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
            'promotion_candidates' => (clone $base)->where('promotion_candidate', true)->count(),
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
            $q->where('promotion_candidate', (bool) $filters['promotion_candidate']);
        }
        if (!empty($filters['rule_id'])) {
            $q->where('rule_id', 'like', '%' . $filters['rule_id'] . '%');
        }

        return $q->paginate(50);
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
