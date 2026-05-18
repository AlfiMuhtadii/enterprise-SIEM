<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class EndpointAgent extends Model
{
    use HasFactory;

    public const HEALTH_ONLINE   = 'online';
    public const HEALTH_DEGRADED = 'degraded';
    public const HEALTH_STALE    = 'stale';
    public const HEALTH_OFFLINE  = 'offline';

    public const HEALTH_STATES = [
        self::HEALTH_ONLINE,
        self::HEALTH_DEGRADED,
        self::HEALTH_STALE,
        self::HEALTH_OFFLINE,
    ];

    protected $fillable = [
        'agent_id', 'host_id', 'host_fingerprint', 'hostname',
        'enrollment_token_hash', 'agent_version', 'public_key_fingerprint',
        'ip_address', 'platform', 'os_family', 'health_state', 'status',
        'enrolled_at', 'last_seen_at', 'metadata',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'enrolled_at' => 'datetime',
        'last_seen_at'=> 'datetime',
    ];

    public function config(): HasOne
    {
        return $this->hasOne(EndpointAgentConfig::class, 'agent_id')
            ->latestOfMany();
    }

    public function configs(): HasMany
    {
        return $this->hasMany(EndpointAgentConfig::class, 'agent_id');
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(EndpointAgentHeartbeat::class, 'agent_id')
            ->orderByDesc('heartbeat_at');
    }

    public function metrics(): HasOne
    {
        return $this->hasOne(EndpointAgentMetric::class, 'agent_id');
    }

    public static function generateAgentId(): string
    {
        return 'agent-' . Str::uuid()->toString();
    }

    public static function hashEnrollmentToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
