<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeerGroupProfile extends Model
{
    // Group types — deterministic, non-sensitive attribute based
    public const GROUP_TYPES = [
        'user_role',
        'host_function',
        'saas_tenant',
        'endpoint_agent',
        'network_destination',
    ];

    public const MAX_GROUP_SIZE = 500;  // bounded group size

    protected $table = 'peer_group_profiles';

    protected $fillable = [
        'peer_group_key',
        'group_type',
        'group_label',
        'criteria',
        'entity_count',
        'member_entity_keys',
        'dimension_stats',
        'advisory_only',
        'computed_at',
    ];

    protected $casts = [
        'criteria'           => 'array',
        'member_entity_keys' => 'array',
        'dimension_stats'    => 'array',
        'entity_count'       => 'integer',
        'advisory_only'      => 'boolean',
        'computed_at'        => 'datetime',
    ];

    public function baselines()
    {
        return EntityBehaviorBaseline::where('peer_group_key', $this->peer_group_key)->get();
    }
}
