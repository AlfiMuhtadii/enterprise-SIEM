<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataErasureAuditEvent extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_REQUESTED = 'requested';
    public const EVENT_APPROVED = 'approved';
    public const EVENT_REJECTED = 'rejected';
    public const EVENT_EXECUTED = 'executed';
    public const EVENT_DRY_RUN = 'dry_run';

    public const EVENT_TYPES = [
        self::EVENT_REQUESTED,
        self::EVENT_APPROVED,
        self::EVENT_REJECTED,
        self::EVENT_EXECUTED,
        self::EVENT_DRY_RUN,
    ];

    protected $fillable = [
        'audit_id', 'request_id', 'event_type', 'tenant_id',
        'table_name', 'row_count', 'actor', 'details', 'created_at',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];
}
