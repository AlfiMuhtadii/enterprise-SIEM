<?php

namespace Tests\Feature;

use Database\Seeders\DemoSocSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_creates_reviewer_data(): void
    {
        $this->seed(DemoSocSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'soc-admin@example.com',
            'role' => 'admin',
        ]);
        $this->assertDatabaseHas('security_incidents', [
            'incident_id' => 'DEMO-INC-001',
            'status' => 'investigating',
        ]);
        $this->assertGreaterThanOrEqual(5, DB::table('security_alerts')->where('alert_id', 'like', 'DEMO-ALERT-%')->count());
        $this->assertGreaterThanOrEqual(5, DB::table('telemetry_events')->where('event_id', 'like', 'demo-tel-%')->count());
        $this->assertDatabaseHas('security_audit_trails', [
            'action' => 'export.download',
            'target_id' => 'jsonl',
        ]);
    }
}
