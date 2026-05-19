<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of a container lifecycle or namespace visibility event.
 * Advisory-only — no container enforcement.
 */
class EndpointContainerActivity extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'activity_id', 'agent_id', 'container_id', 'container_name',
        'image_name', 'activity_type', 'pid', 'process_name',
        'namespace_type', 'host_id', 'trace_id', 'is_advisory',
        'occurred_at', 'created_at',
    ];

    protected $casts = [
        'is_advisory' => 'boolean',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
        'pid'         => 'integer',
    ];

    // Activity types
    public const TYPE_CONTAINER_START     = 'container_start';
    public const TYPE_CONTAINER_STOP      = 'container_stop';
    public const TYPE_NAMESPACE_DETECTED  = 'namespace_detected';
    public const TYPE_BREAKOUT_INDICATOR  = 'breakout_indicator';

    public const ACTIVITY_TYPES = [
        self::TYPE_CONTAINER_START,
        self::TYPE_CONTAINER_STOP,
        self::TYPE_NAMESPACE_DETECTED,
        self::TYPE_BREAKOUT_INDICATOR,
    ];

    // Namespace/runtime types
    public const NS_DOCKER      = 'docker';
    public const NS_CONTAINERD  = 'containerd';
    public const NS_LXC         = 'lxc';
    public const NS_KUBERNETES  = 'kubernetes';
    public const NS_UNKNOWN     = 'unknown';

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }

    public static function generateActivityId(): string
    {
        return 'cta-' . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 36);
    }
}
