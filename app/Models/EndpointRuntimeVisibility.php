<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EndpointRuntimeVisibility extends Model
{
    protected $table = 'endpoint_runtime_visibility';

    public const VISIBILITY_TYPES = ['process_snapshot', 'module_snapshot', 'socket_snapshot', 'registry_snapshot'];

    protected $fillable = [
        'visibility_id', 'endpoint_id', 'tenant_id', 'visibility_type',
        'process_count', 'module_count', 'socket_count', 'registry_key_count',
        'visibility_completeness_pct', 'is_advisory', 'visibility_snapshot',
    ];

    protected $casts = [
        'visibility_completeness_pct' => 'float',
        'is_advisory'                 => 'boolean',
        'visibility_snapshot'         => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EndpointRuntimeVisibility is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
