<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreatHuntResult extends Model
{
    public const UPDATED_AT = null;

    // Result type constants
    public const TYPE_PROCESS_ENTRY      = 'process_entry';
    public const TYPE_PERSISTENCE_ITEM   = 'persistence_item';
    public const TYPE_BEHAVIORAL_FINDING = 'behavioral_finding';
    public const TYPE_EXECUTION_CHAIN    = 'execution_chain';
    public const TYPE_BEACON_PATTERN     = 'beacon_pattern';
    public const TYPE_NETWORK_CORRELATION= 'network_correlation';
    public const TYPE_ALERT              = 'alert';
    public const TYPE_HOST               = 'host';

    protected $fillable = [
        'hunt_id', 'result_type', 'result_source_id', 'result_data', 'trace_id',
    ];

    protected $casts = [
        'result_data' => 'array',
        'created_at'  => 'datetime',
    ];

    public function hunt(): BelongsTo
    {
        return $this->belongsTo(ThreatHunt::class, 'hunt_id');
    }
}
