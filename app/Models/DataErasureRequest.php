<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataErasureRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXECUTED = 'executed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_EXECUTED,
    ];

    protected $fillable = [
        'request_id', 'tenant_id', 'reason', 'status', 'requested_by',
        'approved_by', 'approved_at', 'dry_run', 'executed_at', 'execution_summary',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
        'execution_summary' => 'array',
    ];
}
