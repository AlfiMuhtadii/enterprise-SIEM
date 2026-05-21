<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InfrastructureCostEstimate extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'estimate_id', 'scope', 'backend', 'daily_cost_estimate', 'monthly_cost_estimate',
        'projected_90d_cost', 'cost_unit', 'growth_rate_pct', 'estimation_method',
        'assumptions', 'is_advisory', 'created_at',
    ];

    protected $casts = [
        'daily_cost_estimate'   => 'float',
        'monthly_cost_estimate' => 'float',
        'projected_90d_cost'    => 'float',
        'growth_rate_pct'       => 'float',
        'is_advisory'           => 'boolean',
        'created_at'            => 'datetime',
    ];

    public const SCOPES = ['storage', 'compute', 'replay', 'query', 'total'];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('infrastructure_cost_estimates is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
