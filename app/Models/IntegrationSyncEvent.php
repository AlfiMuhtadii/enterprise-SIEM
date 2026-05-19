<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationSyncEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'sync_id', 'integration_id', 'direction', 'sync_type', 'status',
        'records_processed', 'records_failed', 'dry_run', 'advisory_only',
        'summary', 'error_detail', 'trace_id', 'started_at', 'completed_at', 'created_by',
    ];

    protected $casts = [
        'summary'           => 'array',
        'dry_run'           => 'boolean',
        'advisory_only'     => 'boolean',
        'records_processed' => 'integer',
        'records_failed'    => 'integer',
        'started_at'        => 'datetime',
        'completed_at'      => 'datetime',
    ];

    public const DIRECTIONS = ['inbound', 'outbound'];
    public const SYNC_TYPES = ['full_pull', 'incremental_pull', 'push_case', 'push_notification'];
    public const STATUSES   = ['started', 'completed', 'failed', 'skipped'];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(ExternalIntegration::class, 'integration_id');
    }
}
