<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TelemetryOnboardingPressure extends Model
{
    protected $table = 'telemetry_onboarding_pressure';

    public const PRESSURE_LEVELS = ['normal', 'elevated', 'high', 'critical'];

    protected $fillable = [
        'snapshot_id', 'run_id', 'tenant_id', 'events_per_second',
        'queue_growth_rate', 'storage_growth_mb_per_hour', 'endpoint_count',
        'replay_amplification_factor', 'pressure_ok', 'pressure_level', 'is_advisory',
    ];

    protected $casts = [
        'pressure_ok' => 'boolean',
        'is_advisory' => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TelemetryOnboardingPressure is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
