<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShadowSoakDomainAssessment extends Model
{
    protected $table = 'shadow_soak_domain_assessments';

    protected $fillable = [
        'assessment_id', 'run_id', 'domain',
        'gates_checked', 'gates_passed', 'gates_failed',
        'pass_rate', 'evidence_pass', 'promotion_recommended',
        'recommendation_note', 'tenant_id',
    ];

    protected $casts = [
        'gates_checked'          => 'integer',
        'gates_passed'           => 'integer',
        'gates_failed'           => 'integer',
        'pass_rate'              => 'float',
        'evidence_pass'          => 'boolean',
        'promotion_recommended'  => 'boolean',
    ];
}
