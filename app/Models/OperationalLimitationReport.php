<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalLimitationReport extends Model
{
    protected $fillable = [
        'limitation_report_id', 'limitation_category', 'limitation_severity',
        'limitation_description', 'affected_domain', 'cutover_blocked',
        'mitigation_available', 'is_advisory', 'limitation_evidence',
    ];

    protected $casts = [
        'cutover_blocked'      => 'boolean',
        'mitigation_available' => 'boolean',
        'is_advisory'          => 'boolean',
        'limitation_evidence'  => 'array',
    ];

    public const LIMITATION_CATEGORIES = [
        'advisory_only', 'shadow_scope', 'soak_required',
        'capacity_bound', 'feature_bound',
    ];

    public const LIMITATION_SEVERITIES = ['info', 'low', 'medium', 'high'];
}
