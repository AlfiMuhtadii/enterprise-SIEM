<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityHardeningEvent extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_AUTH_FAILURE       = 'auth_failure';
    public const EVENT_SIGNATURE_FAILURE  = 'signature_failure';
    public const EVENT_SECRET_WARNING     = 'secret_warning';
    public const EVENT_STARTUP_VALIDATION = 'startup_validation';
    public const EVENT_AUDIT_VIOLATION    = 'audit_violation_attempt';
    public const EVENT_SECRET_ROTATION    = 'secret_rotation';

    public const EVENT_TYPES = [
        self::EVENT_AUTH_FAILURE,
        self::EVENT_SIGNATURE_FAILURE,
        self::EVENT_SECRET_WARNING,
        self::EVENT_STARTUP_VALIDATION,
        self::EVENT_AUDIT_VIOLATION,
        self::EVENT_SECRET_ROTATION,
    ];

    protected $fillable = [
        'event_type',
        'service_name',
        'actor',
        'trace_id',
        'details',
        'occurred_at',
    ];

    protected $casts = [
        'details'     => 'array',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public static function record(
        string $type,
        string $service,
        array $details = [],
        ?string $actor = null,
        ?string $traceId = null
    ): self {
        return static::create([
            'event_type'   => $type,
            'service_name' => $service,
            'actor'        => $actor,
            'trace_id'     => $traceId,
            'details'      => $details,
            'occurred_at'  => now(),
            'created_at'   => now(),
        ]);
    }
}
