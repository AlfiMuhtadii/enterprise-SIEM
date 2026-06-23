<?php

namespace App\Models;

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

    public const SEVERITY_ORDER = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'info' => 0];

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
