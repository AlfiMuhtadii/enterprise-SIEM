<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityHardeningFreezeCoverageReport extends Model
{
    protected $table = 'security_hardening_freeze_coverage_reports';

    protected $fillable = [
        'report_id', 'run_id', 'overall_score', 'total_controls',
        'passing_controls', 'failing_controls', 'meets_pass_threshold',
        'advisory_only', 'per_category_scores', 'report_metadata',
    ];

    protected $casts = [
        'meets_pass_threshold' => 'boolean',
        'advisory_only'        => 'boolean',
        'overall_score'        => 'float',
        'per_category_scores'  => 'array',
        'report_metadata'      => 'array',
    ];
}
