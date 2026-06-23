<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DlqRecord extends Model
{
    protected $table = 'dlq_records';

    protected $fillable = [
        'record_id', 'dlq_event_type', 'source_topic', 'source_partition',
        'source_offset', 'consumer_group', 'error_code', 'error_message',
        'raw_payload', 'isolated_at', 'tenant_id', 'status',
        'reviewed_by', 'reviewed_at', 'review_note',
        'replay_requested_by', 'replay_requested_at',
        'replayed_at', 'replay_result',
        'fingerprint', 'occurrence_count', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'source_partition'    => 'integer',
        'source_offset'       => 'integer',
        'error_code'          => 'integer',
        'raw_payload'         => 'array',
        'replay_result'       => 'array',
        'occurrence_count'    => 'integer',
        'isolated_at'         => 'datetime',
        'reviewed_at'         => 'datetime',
        'replay_requested_at' => 'datetime',
        'replayed_at'         => 'datetime',
        'first_seen_at'       => 'datetime',
        'last_seen_at'        => 'datetime',
    ];

    const STATUSES = ['new', 'reviewed', 'ignored', 'replay_requested', 'replayed', 'replay_failed'];

    const EVENT_TYPES = [
        'poison_message_isolated',
        'malformed_record',
        'normalization_failure',
        'invalid_json',
        'publish_failure',
        'unknown',
    ];

    // Only records with raw_payload can be replayed.
    public function isReplayable(): bool
    {
        return $this->raw_payload !== null && !in_array($this->status, ['ignored', 'replayed']);
    }

    public function isActionable(): bool
    {
        return in_array($this->status, ['new', 'reviewed', 'replay_failed']);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DlqNormalizationEvent::class, 'record_id', 'record_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function replayRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replay_requested_by');
    }
}
