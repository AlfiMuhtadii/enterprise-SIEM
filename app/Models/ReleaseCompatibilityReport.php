<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReleaseCompatibilityReport extends Model
{
    protected $fillable = [
        'compatibility_report_id',
        'release_id',
        'from_version',
        'to_version',
        'schema_compatible',
        'migration_safe',
        'rollback_safe',
        'upgrade_advisory_issued',
        'replay_safe',
        'compatibility_evidence',
    ];

    protected $casts = [
        'schema_compatible'        => 'boolean',
        'migration_safe'           => 'boolean',
        'rollback_safe'            => 'boolean',
        'upgrade_advisory_issued'  => 'boolean',
        'replay_safe'              => 'boolean',
        'compatibility_evidence'   => 'array',
    ];
}
