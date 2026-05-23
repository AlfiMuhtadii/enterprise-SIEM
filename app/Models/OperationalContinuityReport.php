<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OperationalContinuityReport extends Model
{
    protected $table = 'operational_continuity_reports';

    protected $fillable = [
        'continuity_id', 'environment', 'continuity_score',
        'replay_continuous', 'queue_continuous', 'telemetry_continuous',
        'storage_continuous', 'overall_continuous', 'is_advisory', 'continuity_evidence',
    ];

    protected $casts = [
        'replay_continuous'    => 'boolean',
        'queue_continuous'     => 'boolean',
        'telemetry_continuous' => 'boolean',
        'storage_continuous'   => 'boolean',
        'overall_continuous'   => 'boolean',
        'is_advisory'          => 'boolean',
        'continuity_evidence'  => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('OperationalContinuityReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
