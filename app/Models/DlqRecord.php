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
        'raw_payload', 'isolated_at', 'tenant_id', 'replayable', 'error_reason',
        'status', 'reviewed_by', 'reviewed_at', 'review_note',
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
        'replayable'          => 'boolean',
        'occurrence_count'    => 'integer',
        'isolated_at'         => 'datetime',
        'reviewed_at'         => 'datetime',
        'replay_requested_at' => 'datetime',
        'replayed_at'         => 'datetime',
        'first_seen_at'       => 'datetime',
        'last_seen_at'        => 'datetime',
    ];

    const STATUSES = ['new', 'reviewed', 'ignored', 'replay_requested', 'replayed', 'replay_failed'];

    // dlq:replay only sends these types to the normalizer /v1/normalize.
    // Pipeline failure types (correlation_parse_error, correlation_publish_error,
    // alert_write_failed) are NEVER forwarded to the normalizer — they were produced
    // after normalization and routing them back would corrupt the pipeline.
    const NORMALIZER_SAFE_EVENT_TYPES = [
        'normalization_failure',
        'publish_failure',
        'malformed_record',
        'invalid_json',
    ];

    const EVENT_TYPES = [
        'poison_message_isolated',
        'malformed_record',
        'normalization_failure',
        'invalid_json',
        'publish_failure',
        // Pipeline failure types — from xdr.correlation_failed / xdr.alert_write_failed
        'correlation_parse_error',
        'correlation_publish_error',
        'alert_write_failed',
        'unknown',
    ];

    // Records are replayable when the error class is transient AND raw_payload is present.
    // replayable=true but raw_payload=null means the error class is transient but the
    // replay payload wasn't captured (e.g. correlation publish failure — data not stored).
    public function isReplayable(): bool
    {
        return $this->replayable
            && $this->raw_payload !== null
            && !in_array($this->status, ['ignored', 'replayed']);
    }

    // True when error class is transient (regardless of whether replay data is present).
    public function isReplayableClass(): bool
    {
        return (bool) $this->replayable;
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
