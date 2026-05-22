<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class GovernanceReportingRun extends Model
{
    public const REPORT_TYPES = ['weekly', 'monthly', 'replay_durability', 'telemetry_continuity', 'analyst_efficiency', 'infrastructure_stability'];
    public const VERDICTS      = ['pass', 'advisory', 'degraded', 'fail'];

    protected $fillable = [
        'run_id', 'tenant_id', 'report_type', 'window_type',
        'overall_health_score', 'telemetry_passing', 'replay_passing',
        'analyst_stable', 'infrastructure_stable', 'tenant_isolation_passing',
        'governance_verdict', 'is_advisory', 'report_summary',
    ];

    protected $casts = [
        'overall_health_score'    => 'float',
        'telemetry_passing'       => 'boolean',
        'replay_passing'          => 'boolean',
        'analyst_stable'          => 'boolean',
        'infrastructure_stable'   => 'boolean',
        'tenant_isolation_passing'=> 'boolean',
        'is_advisory'             => 'boolean',
        'report_summary'          => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('GovernanceReportingRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
