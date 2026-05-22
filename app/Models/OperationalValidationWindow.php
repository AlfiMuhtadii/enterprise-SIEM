<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OperationalValidationWindow extends Model
{
    public const WINDOW_TYPES = ['7d', '14d', '30d'];

    protected $fillable = [
        'window_id', 'tenant_id', 'window_type', 'window_start', 'window_end',
        'telemetry_continuity_pct', 'replay_recovery_success_pct',
        'avg_queue_lag', 'storage_growth_gb', 'worker_restart_count',
        'criteria_met', 'is_advisory', 'window_summary',
    ];

    protected $casts = [
        'window_start'                => 'datetime',
        'window_end'                  => 'datetime',
        'telemetry_continuity_pct'    => 'float',
        'replay_recovery_success_pct' => 'float',
        'avg_queue_lag'               => 'float',
        'storage_growth_gb'           => 'float',
        'criteria_met'                => 'boolean',
        'is_advisory'                 => 'boolean',
        'window_summary'              => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('OperationalValidationWindow is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
