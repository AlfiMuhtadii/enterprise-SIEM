<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AdvisoryFinding extends Model
{
    protected $fillable = [
        'finding_id',
        'rule_id',
        'domain',
        'source_topic',
        'alert_type',
        'severity',
        'confidence',
        'actor_key',
        'tenant_id',
        'evidence',
        'source_event_ids',
        'promotion_candidate',
        'promotion_blocker',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'review_outcome',
        'fingerprint',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'evidence'            => 'array',
        'source_event_ids'    => 'array',
        'promotion_candidate' => 'boolean',
        'confidence'          => 'float',
        'occurrence_count'    => 'integer',
        'reviewed_at'         => 'datetime',
        'first_seen_at'       => 'datetime',
        'last_seen_at'        => 'datetime',
    ];

    public const STATUSES = ['new', 'reviewed', 'dismissed', 'nominated'];

    public const REVIEW_OUTCOMES = [
        'confirmed_detection' => 'Confirmed Detection',
        'false_positive' => 'False Positive',
        'inconclusive' => 'Inconclusive',
    ];

    private const OUTCOMES_BLOCKING_PROMOTION = ['false_positive', 'inconclusive'];

    public const SEVERITY_ORDER = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'info' => 0];

    public function scopeWithoutRehearsal(Builder $query): Builder
    {
        return $query->where(function (Builder $records): void {
            $records->whereNull('evidence->shadow_evidence_origin')
                ->orWhere('evidence->shadow_evidence_origin', '!=', 'rehearsal');
        });
    }

    public function isRehearsal(): bool
    {
        return ($this->evidence['shadow_evidence_origin'] ?? null) === 'rehearsal';
    }

    public function hasIncompleteLineage(): bool
    {
        return ($this->evidence['evidence_lineage_complete'] ?? null) === false
            || ($this->evidence['evidence_lineage_truncated'] ?? null) === true;
    }

    public function reviewBlocksPromotion(): bool
    {
        return in_array($this->review_outcome, self::OUTCOMES_BLOCKING_PROMOTION, true);
    }

    public function isReviewPromotionCandidate(): bool
    {
        return $this->promotion_candidate && !$this->reviewBlocksPromotion();
    }

    public function scopeReviewPromotionCandidate(Builder $query, bool $candidate = true): Builder
    {
        if ($candidate) {
            return $query->where('promotion_candidate', true)->where(function (Builder $eligible): void {
                $eligible->whereNull('review_outcome')->orWhereNotIn('review_outcome', self::OUTCOMES_BLOCKING_PROMOTION);
            });
        }

        return $query->where(function (Builder $ineligible): void {
            $ineligible->where('promotion_candidate', false)->orWhereIn('review_outcome', self::OUTCOMES_BLOCKING_PROMOTION);
        });
    }

    public function events()
    {
        return $this->hasMany(AdvisoryFindingEvent::class, 'finding_id', 'finding_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isActionable(): bool
    {
        return in_array($this->status, ['new', 'reviewed'], true);
    }

    public function severityLabel(): string
    {
        return match ($this->severity) {
            'critical' => 'CRIT',
            'high'     => 'HIGH',
            'medium'   => 'MED',
            'low'      => 'LOW',
            default    => 'INFO',
        };
    }
}
