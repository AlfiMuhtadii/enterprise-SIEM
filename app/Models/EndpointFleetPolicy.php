<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EndpointFleetPolicy extends Model
{
    public const REASON_MANUAL       = 'manual';
    public const REASON_BULK_ROLLOUT = 'bulk_rollout';
    public const REASON_ROLLBACK     = 'rollback';
    public const REASON_RE_ENROLLMENT= 're_enrollment';

    protected $table = 'endpoint_fleet_policies';

    protected $fillable = [
        'policy_id', 'name', 'description', 'policy_version',
        'config', 'config_hash', 'is_active', 'rollback_supported',
        'previous_policy_id', 'assigned_agent_count', 'activated_at',
        'deactivated_at', 'created_by',
    ];

    protected $casts = [
        'config'          => 'array',
        'is_active'       => 'boolean',
        'rollback_supported' => 'boolean',
        'assigned_agent_count' => 'integer',
        'activated_at'    => 'datetime',
        'deactivated_at'  => 'datetime',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(EndpointAgentPolicyAssignment::class, 'policy_id', 'policy_id');
    }

    public static function generatePolicyId(): string
    {
        return 'fleet-policy-' . Str::uuid()->toString();
    }

    public static function hashConfig(array $config): string
    {
        // Recursively sort by key for deterministic hashing regardless of insertion order
        array_walk_recursive($config, fn (&$v) => is_array($v) ? ksort($v) : null);
        ksort($config);
        return hash('sha256', json_encode($config));
    }
}
