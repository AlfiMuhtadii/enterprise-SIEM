<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoarPlaybookVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'version_id', 'playbook_id', 'version', 'version_hash',
        'playbook_snapshot', 'change_reason', 'created_by', 'created_at',
    ];

    protected $casts = [
        'playbook_snapshot' => 'array',
        'created_at'        => 'datetime',
    ];

    public static function hashPlaybook(SoarPlaybook $playbook): string
    {
        $fields = [
            'playbook_id'           => $playbook->playbook_id,
            'version'               => $playbook->version,
            'execution_scope'       => $playbook->execution_scope,
            'allowed_actions'       => $playbook->allowed_actions ?? [],
            'simulation_required'   => $playbook->simulation_required,
            'dual_approval_required'=> $playbook->dual_approval_required,
        ];
        ksort($fields);
        return hash('sha256', json_encode($fields));
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('soar_playbook_versions is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
