<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only audit trail for DLQ normalization review actions.
// Table: dlq_normalization_events
class DlqNormalizationEvent extends Model
{
    protected $table = 'dlq_normalization_events';

    public $timestamps = false;

    protected $fillable = [
        'event_id', 'record_id', 'event_type', 'analyst_id', 'tenant_id', 'metadata',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    const EVENT_TYPES = [
        'record_created',
        'occurrence_incremented',
        'reviewed',
        'ignored',
        'replay_requested',
        'replayed',
        'replay_failed',
    ];

    public function dlqRecord(): BelongsTo
    {
        return $this->belongsTo(DlqRecord::class, 'record_id', 'record_id');
    }
}
