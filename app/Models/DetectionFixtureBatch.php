<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only. NEVER UPDATE or DELETE. */
class DetectionFixtureBatch extends Model
{
    protected $table = 'detection_fixture_batches';

    public $timestamps = false;

    protected $fillable = [
        'batch_id', 'batch_name', 'tier', 'rules_total',
        'fixtures_valid', 'fixtures_invalid',
        'is_advisory', 'promotion_blocked', 'tenant_id', 'run_at',
    ];

    protected $casts = [
        'is_advisory'       => 'boolean',
        'promotion_blocked' => 'boolean',
    ];
}
