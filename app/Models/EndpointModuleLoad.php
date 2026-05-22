<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EndpointModuleLoad extends Model
{
    protected $fillable = [
        'load_id', 'endpoint_id', 'tenant_id', 'module_name', 'module_path',
        'process_name', 'module_hash_sha256', 'is_signed', 'suspicious_lineage',
        'load_frequency', 'network_correlated', 'is_advisory', 'load_context',
    ];

    protected $casts = [
        'is_signed'          => 'boolean',
        'suspicious_lineage' => 'boolean',
        'network_correlated' => 'boolean',
        'is_advisory'        => 'boolean',
        'load_context'       => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EndpointModuleLoad is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
