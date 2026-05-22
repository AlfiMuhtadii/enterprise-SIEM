<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TelemetryContinuityReport extends Model
{
    protected $fillable = [
        'report_id', 'soak_run_id', 'observation_window_minutes',
        'expected_events', 'observed_events', 'continuity_pct',
        'gap_count', 'total_gap_seconds', 'continuity_ok', 'verdict', 'is_advisory',
    ];

    protected $casts = [
        'continuity_ok' => 'boolean',
        'is_advisory'   => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TelemetryContinuityReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
