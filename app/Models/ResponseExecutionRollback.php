<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponseExecutionRollback extends Model
{
    public const UPDATED_AT = null; // append-only

    public const TYPE_MANUAL      = 'manual';
    public const TYPE_TIMEOUT     = 'timeout';
    public const TYPE_AUTO_FAILED = 'auto_failed';

    public const STATUS_INITIATED = 'initiated';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'rollback_id', 'execution_id', 'rollback_type', 'initiated_by',
        'initiated_at', 'completed_at', 'status', 'rollback_evidence', 'trace_id', 'tenant_id',
    ];

    protected $casts = [
        'rollback_evidence' => 'array',
        'initiated_at'      => 'datetime',
        'completed_at'      => 'datetime',
        'created_at'        => 'datetime',
    ];

    public static function generateRollbackId(): string
    {
        $year = (int) date('Y');
        $max  = static::whereYear('created_at', $year)->max('id') ?? 0;
        return sprintf('ROLL-%d-%05d', $year, $max + 1);
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(ResponseExecution::class, 'execution_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
