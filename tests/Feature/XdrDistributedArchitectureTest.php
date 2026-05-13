<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class XdrDistributedArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_distributed_xdr_operational_tables_and_health_endpoint_work(): void
    {
        foreach (['xdr_service_health', 'xdr_stream_metrics', 'xdr_storage_health', 'xdr_validation_runs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
        }

        $this->get('/health/services/xdr-correlation')
            ->assertOk()
            ->assertJsonPath('service', 'xdr-correlation')
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('xdr_service_health', [
            'service_name' => 'xdr-correlation',
            'status' => 'healthy',
        ]);
    }

    public function test_xdr_operational_commands_record_metrics_and_dashboard_shows_them(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        DB::table('telemetry_events')->insert([
            'ts' => now(),
            'event_id' => 'xdr-dist-telemetry-1',
            'telemetry_type' => 'identity',
            'event_type' => 'login_success',
            'host_id' => 'azure-ad',
            'xdr_user' => 'ops@example.com',
            'source_ip' => '198.51.100.10',
            'risk_score' => 0.8,
            'event_source' => 'azure-signin',
            'payload' => json_encode(['sample' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('xdr:storage-validate')->assertSuccessful();
        $this->artisan('xdr:stream-metrics')->assertSuccessful();
        $this->artisan('xdr:validate-realism --normal=100 --malicious=20 --replay-seconds=10')->assertSuccessful();

        $this->assertDatabaseHas('xdr_storage_health', ['store_name' => 'incidents_workflow_rbac', 'driver' => 'postgresql']);
        $this->assertDatabaseHas('xdr_stream_metrics', ['topic' => 'telemetry.normalized']);
        $this->assertDatabaseHas('xdr_validation_runs', ['mode' => 'mixed_normal_malicious_replay']);

        $this->actingAs($analyst)
            ->get('/soc')
            ->assertOk()
            ->assertSee('Service Separation')
            ->assertSee('Streaming Backbone')
            ->assertSee('Specialized Storage')
            ->assertSee('Latest XDR validation');
    }
}
