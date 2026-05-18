<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EndpointPersistenceItem extends Model
{
    public const ITEM_TYPE_SYSTEMD   = 'systemd_service';
    public const ITEM_TYPE_CRON      = 'cron_job';
    public const ITEM_TYPE_STARTUP   = 'startup_script';

    protected $fillable = [
        'agent_id', 'item_type', 'item_key', 'item_name', 'item_path',
        'is_new', 'first_seen_at', 'last_seen_at', 'trace_id',
    ];

    protected $casts = [
        'is_new'       => 'boolean',
        'first_seen_at'=> 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }
}
