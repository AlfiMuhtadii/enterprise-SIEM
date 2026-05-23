<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RecoveryDependencyGraph extends Model
{
    protected $table = 'recovery_dependency_graphs';

    protected $fillable = [
        'graph_id', 'root_service', 'dependency_depth', 'total_dependencies',
        'circular_dependency_detected', 'all_dependencies_healthy',
        'is_advisory', 'dependency_map',
    ];

    protected $casts = [
        'circular_dependency_detected' => 'boolean',
        'all_dependencies_healthy'     => 'boolean',
        'is_advisory'                  => 'boolean',
        'dependency_map'               => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('RecoveryDependencyGraph is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
