<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocOperationalReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_health_endpoints_are_available(): void
    {
        $this->getJson('/health/live')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonStructure(['ok', 'checks']);
    }

    public function test_operational_metrics_require_authenticated_soc_access(): void
    {
        $this->getJson('/soc/api/metrics')->assertUnauthorized();

        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)
            ->getJson('/soc/api/metrics')
            ->assertOk()
            ->assertJsonStructure([
                'application',
                'scheduler',
                'ingestion',
                'queue',
                'notifications',
                'incidents',
            ]);
    }
}
