<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EndpointProcessAncestryValidation extends Model
{
    protected $table = 'endpoint_process_ancestry_validation';

    protected $fillable = [
        'validation_id', 'endpoint_id', 'tenant_id', 'process_name', 'parent_process_name',
        'parent_found', 'orphaned_chain', 'lineage_consistent', 'lineage_confidence',
        'ancestry_divergence_detected', 'replay_ordered', 'is_advisory', 'ancestry_evidence',
    ];

    protected $casts = [
        'parent_found'                  => 'boolean',
        'orphaned_chain'                => 'boolean',
        'lineage_consistent'            => 'boolean',
        'lineage_confidence'            => 'float',
        'ancestry_divergence_detected'  => 'boolean',
        'replay_ordered'                => 'boolean',
        'is_advisory'                   => 'boolean',
        'ancestry_evidence'             => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EndpointProcessAncestryValidation is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
