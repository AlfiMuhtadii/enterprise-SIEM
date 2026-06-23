<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvisoryFindingEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'finding_id',
        'event_type',
        'analyst_id',
        'tenant_id',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public const EVENT_TYPES = [
        'finding_created',
        'occurrence_incremented',
        'reviewed',
        'dismissed',
        'nominated_for_promotion',
        'review_note_updated',
    ];

    public function finding()
    {
        return $this->belongsTo(AdvisoryFinding::class, 'finding_id', 'finding_id');
    }
}
