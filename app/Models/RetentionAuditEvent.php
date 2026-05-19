<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RetentionAuditEvent extends Model
{
    public const UPDATED_AT = null; // append-only

    public const EVENT_PREVIEW        = 'preview';
    public const EVENT_DRY_RUN        = 'dry_run';
    public const EVENT_DELETION       = 'deletion';
    public const EVENT_ARCHIVE        = 'archive';
    public const EVENT_POLICY_UPDATED = 'policy_updated';

    protected $fillable = [
        'audit_id', 'policy_id', 'execution_id', 'event_type',
        'domain', 'row_count', 'dry_run', 'actor_id', 'details', 'trace_id',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'details' => 'array',
    ];

    public static function generateAuditId(): string
    {
        return 'raud-' . Str::uuid();
    }
}
