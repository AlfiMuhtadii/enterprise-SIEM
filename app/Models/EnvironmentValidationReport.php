<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EnvironmentValidationReport extends Model
{
    protected $table = 'environment_validation_reports';

    protected $fillable = [
        'validation_id', 'environment', 'services_healthy', 'queue_available',
        'storage_available', 'replay_continuous', 'telemetry_continuous',
        'overall_valid', 'is_advisory', 'validation_details',
    ];

    protected $casts = [
        'services_healthy'   => 'boolean',
        'queue_available'    => 'boolean',
        'storage_available'  => 'boolean',
        'replay_continuous'  => 'boolean',
        'telemetry_continuous'=> 'boolean',
        'overall_valid'      => 'boolean',
        'is_advisory'        => 'boolean',
        'validation_details' => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EnvironmentValidationReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
