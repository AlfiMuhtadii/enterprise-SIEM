<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShadowSoakConfidenceBand extends Model
{
    protected $table = 'shadow_soak_confidence_bands';

    protected $fillable = [
        'band_id', 'run_id', 'domain',
        'band_low', 'band_medium', 'band_high', 'band_critical',
        'high_plus_rate', 'tenant_id',
    ];

    protected $casts = [
        'band_low'      => 'integer',
        'band_medium'   => 'integer',
        'band_high'     => 'integer',
        'band_critical' => 'integer',
        'high_plus_rate'=> 'float',
    ];
}
