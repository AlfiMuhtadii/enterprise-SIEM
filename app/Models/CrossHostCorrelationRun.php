<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrossHostCorrelationRun extends Model
{
    protected $fillable = [
        'run_id', 'host_ids', 'correlation_type', 'host_count',
        'propagation_detected', 'correlation_confidence', 'shared_indicators', 'triggered_by',
    ];

    protected $casts = [
        'host_ids'               => 'array',
        'propagation_detected'   => 'boolean',
        'correlation_confidence' => 'float',
        'shared_indicators'      => 'array',
    ];

    public const CORRELATION_TYPES = [
        'lateral_movement', 'shared_c2', 'credential_reuse',
        'synchronized_activity', 'staged_propagation',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('CrossHostCorrelationRun is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
