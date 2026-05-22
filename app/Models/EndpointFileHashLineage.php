<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EndpointFileHashLineage extends Model
{
    protected $table = 'endpoint_file_hash_lineage';

    protected $fillable = [
        'lineage_id', 'endpoint_id', 'tenant_id', 'file_hash_sha256', 'file_path',
        'process_name', 'parent_hash_sha256', 'renamed_executable', 'reuse_count',
        'suspicious_propagation', 'is_advisory', 'lineage_context',
    ];

    protected $casts = [
        'renamed_executable'     => 'boolean',
        'suspicious_propagation' => 'boolean',
        'is_advisory'            => 'boolean',
        'lineage_context'        => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EndpointFileHashLineage is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
