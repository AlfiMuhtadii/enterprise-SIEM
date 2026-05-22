<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DeploymentIntegrityReport extends Model
{
    protected $table = 'deployment_integrity_reports';

    protected $fillable = [
        'report_id', 'package_id', 'version', 'validation_passed',
        'hash_match', 'signature_valid', 'dependency_drift_detected',
        'replay_safe', 'is_advisory', 'integrity_evidence',
    ];

    protected $casts = [
        'validation_passed'         => 'boolean',
        'hash_match'                => 'boolean',
        'signature_valid'           => 'boolean',
        'dependency_drift_detected' => 'boolean',
        'replay_safe'               => 'boolean',
        'is_advisory'               => 'boolean',
        'integrity_evidence'        => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('DeploymentIntegrityReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
