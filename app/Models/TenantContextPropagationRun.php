<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TenantContextPropagationRun extends Model
{
    protected $fillable = [
        'run_id', 'tenant_id', 'trace_id', 'context_ok',
        'hops_total', 'hops_validated', 'attribution_failures',
        'context_frames', 'failure_reason', 'is_advisory',
    ];

    protected $casts = [
        'context_ok'     => 'boolean',
        'is_advisory'    => 'boolean',
        'context_frames' => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TenantContextPropagationRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
