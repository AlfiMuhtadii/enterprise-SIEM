<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EndpointRegistryTimeline extends Model
{
    public const MODIFICATION_TYPES = ['create', 'modify', 'delete'];
    public const KEY_CATEGORIES     = ['persistence', 'startup', 'service', 'autorun', 'other'];

    protected $fillable = [
        'timeline_id', 'endpoint_id', 'tenant_id', 'registry_key', 'registry_value_name',
        'modification_type', 'key_category', 'process_name', 'suspicious_lineage',
        'is_advisory', 'registry_context',
    ];

    protected $casts = [
        'suspicious_lineage' => 'boolean',
        'is_advisory'        => 'boolean',
        'registry_context'   => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EndpointRegistryTimeline is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
