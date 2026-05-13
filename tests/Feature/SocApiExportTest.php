<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocApiExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_soc_api_stats_returns_expected_keys(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)
            ->getJson('/soc/api/stats')
            ->assertOk()
            ->assertJsonStructure(['incidents_by_status', 'incidents_by_severity', 'alert_distribution', 'quality_history']);
    }

    public function test_export_requires_analyst_or_admin(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get('/soc/exports/jsonl')->assertForbidden();
    }

    public function test_admin_can_download_jsonl_export_and_audit_is_recorded(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-test-export',
            'detected_at' => now(),
            'alert_type' => 'TEST_ALERT',
            'detector_name' => 'test',
            'detector_version' => 'v1',
            'severity' => 'high',
            'score' => 0.9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get('/soc/exports/jsonl')->assertOk();

        $this->assertDatabaseHas('security_audit_trails', [
            'actor' => $admin->email,
            'action' => 'export.download',
            'target_type' => 'export',
            'target_id' => 'jsonl',
        ]);
    }

    public function test_webhook_test_logs_delivery_audit(): void
    {
        Http::fake(['https://example.test/*' => Http::response(['ok' => true], 200)]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/soc/exports/test/webhook', [
            'url' => 'https://example.test/webhook',
        ])->assertRedirect();

        $this->assertDatabaseHas('security_audit_trails', [
            'actor' => $admin->email,
            'action' => 'export.webhook_test',
            'target_id' => 'webhook',
        ]);
    }
}
