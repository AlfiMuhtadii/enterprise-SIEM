<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyValidationRecord extends Model
{
    protected $fillable = [
        'record_id', 'event_fingerprint', 'source_topic', 'trace_id', 'event_id',
        'processing_state', 'is_duplicate', 'replay_count', 'corruption_risk',
    ];

    protected $casts = [
        'is_duplicate'    => 'boolean',
        'replay_count'    => 'integer',
        'corruption_risk' => 'boolean',
    ];

    public const STATE_FIRST_SEEN       = 'first_seen';
    public const STATE_PROCESSED        = 'processed';
    public const STATE_REPLAYED_SAFE    = 'replayed_safe';
    public const STATE_DUPLICATE        = 'duplicate_detected';

    public const STATES = [
        self::STATE_FIRST_SEEN,
        self::STATE_PROCESSED,
        self::STATE_REPLAYED_SAFE,
        self::STATE_DUPLICATE,
    ];

    public static function computeFingerprint(string $eventId, string $traceId, string $topic): string
    {
        return hash('sha256', "{$eventId}:{$traceId}:{$topic}");
    }
}
