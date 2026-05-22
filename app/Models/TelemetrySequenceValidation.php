<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetrySequenceValidation extends Model
{
    protected $fillable = [
        'validation_id', 'agent_id', 'host_id', 'expected_sequence', 'observed_sequence',
        'gap_count', 'duplicate_count', 'continuity_ok', 'verdict',
    ];

    protected $casts = ['continuity_ok' => 'boolean'];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('TelemetrySequenceValidation is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
