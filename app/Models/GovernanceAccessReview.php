<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GovernanceAccessReview extends Model
{
    protected $fillable = [
        'review_id', 'subject_user', 'privileged_role', 'review_status',
        'review_window_start', 'review_window_end', 'is_stale', 'days_since_last_review',
        'reviewer_id', 'reviewed_at', 'review_decision_rationale',
    ];

    protected $casts = [
        'review_window_start'   => 'date',
        'review_window_end'     => 'date',
        'is_stale'              => 'boolean',
        'days_since_last_review'=> 'integer',
        'reviewed_at'           => 'datetime',
    ];

    public const STATUSES = ['open', 'in_review', 'approved', 'revoked', 'expired'];

    public const STALE_THRESHOLD_DAYS = 90;

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(GovernanceReviewFinding::class, 'review_id', 'review_id');
    }
}
