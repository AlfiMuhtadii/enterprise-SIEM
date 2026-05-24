<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemoReadinessSnapshot extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'snapshot_id', 'php_tests_passed', 'python_tests_passed',
        'rule_registry_pass', 'contracts_pass', 'resilience_pass',
        'fleet_sim_pass', 'domain_count', 'total_rules',
        'overall_ready', 'snapshot_hash',
    ];

    protected $casts = [
        'rule_registry_pass' => 'boolean',
        'contracts_pass'     => 'boolean',
        'resilience_pass'    => 'boolean',
        'fleet_sim_pass'     => 'boolean',
        'overall_ready'      => 'boolean',
    ];
}
