<?php

namespace Database\Factories;

use App\Models\Investigation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InvestigationFactory extends Factory
{
    protected $model = Investigation::class;

    public function definition(): array
    {
        $userId = User::factory()->create(['role' => 'analyst'])->id;
        return [
            'investigation_id' => 'INV-' . date('Y') . '-' . str_pad(fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'title'            => fake()->sentence(5),
            'description'      => fake()->paragraph(),
            'state'            => 'triaged',
            'severity'         => 'high',
            'priority'         => 2,
            'created_by'       => $userId,
            'assigned_to'      => $userId,
            'trace_id'         => 'trace-' . Str::uuid(),
        ];
    }
}
