<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvidenceIntegrityFailure extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'failure_id', 'run_id', 'evidence_type', 'evidence_id', 'failure_type',
        'description', 'expected_hash', 'actual_hash', 'drift_detected',
        'trace_id', 'created_at',
    ];

    protected $casts = [
        'drift_detected'=> 'boolean',
        'created_at'    => 'datetime',
    ];

    public const FAILURE_TYPES = [
        'hash_mismatch', 'linkage_broken', 'trace_gap', 'tamper_indicator', 'missing_record',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('evidence_integrity_failures is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
