<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoarExecutionResult extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'result_id', 'plan_id', 'step_id', 'result_type', 'success',
        'result_summary', 'result_data', 'is_simulation', 'is_advisory',
        'trace_id', 'created_by', 'created_at',
    ];

    protected $casts = [
        'success'     => 'boolean',
        'result_data' => 'array',
        'is_simulation'=> 'boolean',
        'is_advisory' => 'boolean',
        'created_at'  => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('soar_execution_results is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
