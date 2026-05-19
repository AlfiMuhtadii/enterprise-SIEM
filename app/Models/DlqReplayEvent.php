<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DlqReplayEvent extends Model
{
    public const UPDATED_AT = null; // append-only

    public const TYPE_ENQUEUED  = 'enqueued';
    public const TYPE_SIMULATED = 'simulated';
    public const TYPE_REPLAYED  = 'replayed';
    public const TYPE_SKIPPED   = 'skipped'; // dedup skip
    public const TYPE_FAILED    = 'failed';

    protected $fillable = [
        'event_id', 'job_id', 'event_type', 'sequence_offset',
        'dry_run', 'details', 'trace_id',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'details' => 'array',
    ];

    public static function generateEventId(): string
    {
        return 'dlqev-' . Str::uuid();
    }
}
