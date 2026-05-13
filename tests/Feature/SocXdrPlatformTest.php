<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SocXdrPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_xdr_schema_fields_are_available(): void
    {
        foreach (['xdr_user', 'xdr_host', 'source_ip', 'destination_ip', 'domain', 'file_hash', 'email_sender', 'email_recipient', 'cloud_account', 'xdr_action', 'xdr_result', 'risk_score', 'event_source'] as $column) {
            $this->assertTrue(Schema::hasColumn('telemetry_events', $column), "Missing telemetry_events.{$column}");
        }

        foreach (['xdr_domains', 'involved_users', 'involved_hosts', 'involved_cloud_accounts', 'involved_email_artifacts', 'involved_external_ips', 'xdr_kill_chain_summary'] as $column) {
            $this->assertTrue(Schema::hasColumn('security_incidents', $column), "Missing security_incidents.{$column}");
        }
    }

    public function test_dashboard_shows_xdr_cross_domain_summary(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);

        $base = [
            'src_ip' => null,
            'dst_ip' => null,
            'dst_port' => null,
            'protocol' => null,
            'process_name' => null,
            'user_name_hash' => null,
            'xdr_host' => null,
            'source_ip' => null,
            'destination_ip' => null,
            'domain' => null,
            'file_hash' => null,
            'email_sender' => null,
            'email_recipient' => null,
            'cloud_account' => null,
            'xdr_action' => null,
            'xdr_result' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('telemetry_events')->insert([
            $base + [
                'ts' => now(),
                'event_id' => 'xdr-email-1',
                'telemetry_type' => 'email',
                'event_type' => 'phishing_email',
                'host_id' => 'mailbox-alice',
                'xdr_user' => 'alice@example.com',
                'email_sender' => 'evil@example.net',
                'email_recipient' => 'alice@example.com',
                'domain' => 'evil.example.net',
                'risk_score' => 0.9,
                'event_source' => 'microsoft365',
                'payload' => json_encode(['sample' => true]),
            ],
            $base + [
                'ts' => now(),
                'event_id' => 'xdr-identity-1',
                'telemetry_type' => 'identity',
                'event_type' => 'login_success',
                'host_id' => 'azure-ad',
                'xdr_user' => 'alice@example.com',
                'source_ip' => '203.0.113.91',
                'risk_score' => 0.85,
                'event_source' => 'azure-signin',
                'payload' => json_encode(['sample' => true]),
            ],
            $base + [
                'ts' => now(),
                'event_id' => 'xdr-cloud-1',
                'telemetry_type' => 'cloud',
                'event_type' => 'new_access_key_created',
                'host_id' => 'aws-111122223333',
                'xdr_user' => 'alice@example.com',
                'cloud_account' => '111122223333',
                'source_ip' => '203.0.113.91',
                'risk_score' => 0.8,
                'event_source' => 'aws-cloudtrail',
                'payload' => json_encode(['sample' => true]),
            ],
        ]);

        $this->actingAs($analyst)
            ->get('/soc?minutes=60')
            ->assertOk()
            ->assertSee('XDR Cross-Domain Visibility')
            ->assertSee('Identity Risk')
            ->assertSee('Cloud Risk')
            ->assertSee('Email Threats');
    }
}
