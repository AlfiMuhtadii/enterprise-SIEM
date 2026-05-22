<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TenantReplayLineage extends Model
{
    protected $table = 'tenant_replay_lineage';

    protected $fillable = [
        'lineage_id', 'tenant_id', 'replay_id', 'parent_replay_id',
        'origin_tenant_id', 'replay_depth', 'lineage_chain',
        'lineage_clean', 'is_advisory',
    ];

    protected $casts = [
        'lineage_chain' => 'array',
        'lineage_clean' => 'boolean',
        'is_advisory'   => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TenantReplayLineage is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
