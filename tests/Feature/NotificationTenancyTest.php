<?php

namespace Tests\Feature;

use App\Models\TenantNotificationSetting;
use App\Services\SocNotifier;
use App\Services\TenantBoundaryService;
use App\Services\TenantNotificationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * NOTIFY-TENANCY-GAP — tenant-aware SOC notification routing + audit isolation.
 */
class NotificationTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('notifications_soc.webhook_url', 'https://global.test/webhook');
        Config::set('notifications_soc.slack_url', 'https://global.test/slack');
        Config::set('notifications_soc.discord_url', 'https://global.test/discord');
    }

    private function insertIncident(string $id, ?string $tenantId): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => $id,
            'title' => 'Overdue critical',
            'status' => 'open',
            'severity' => 'critical',
            'confidence' => 0.9,
            'tenant_id' => $tenantId,
            'first_seen_at' => now()->subHours(2),
            'last_seen_at' => now()->subHour(),
            'sla_due_at' => now()->subMinutes(10),
            'escalation_level' => 0,
            'affected_entities' => json_encode([]),
            'timeline' => json_encode([]),
            'mitre_mapping' => json_encode([]),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =========================================================================
    // Schema
    // =========================================================================

    public function test_tenant_notification_settings_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('tenant_notification_settings'));
    }

    public function test_notification_delivery_logs_has_tenant_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('notification_delivery_logs', 'tenant_id'));
    }

    public function test_settings_table_registered_isolated_and_mutable(): void
    {
        $this->assertContains('tenant_notification_settings', TenantBoundaryService::ISOLATED_TABLES);
        $this->assertContains('tenant_notification_settings', TenantBoundaryService::MUTABLE_TABLES);
        $this->assertNotContains('tenant_notification_settings', TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES);
    }

    // =========================================================================
    // Resolver
    // =========================================================================

    public function test_resolver_null_tenant_returns_global_targets(): void
    {
        $resolved = (new TenantNotificationResolver())->resolve(null);

        $this->assertSame('https://global.test/webhook', $resolved['webhook']);
        $this->assertSame('https://global.test/slack', $resolved['slack']);
        $this->assertSame('global', $resolved['source']);
        $this->assertTrue($resolved['enabled']);
    }

    public function test_resolver_unconfigured_tenant_falls_back_to_global(): void
    {
        $resolved = (new TenantNotificationResolver())->resolve('tenant-unknown');

        $this->assertSame('https://global.test/webhook', $resolved['webhook']);
        $this->assertSame('global_fallback', $resolved['source']);
    }

    public function test_resolver_configured_tenant_returns_tenant_targets(): void
    {
        TenantNotificationSetting::create([
            'tenant_id' => 'tenant-a',
            'webhook_url' => 'https://tenant-a.test/webhook',
            'slack_url' => 'https://tenant-a.test/slack',
            'discord_url' => 'https://tenant-a.test/discord',
            'enabled' => true,
        ]);

        $resolved = (new TenantNotificationResolver())->resolve('tenant-a');

        $this->assertSame('https://tenant-a.test/webhook', $resolved['webhook']);
        $this->assertSame('https://tenant-a.test/slack', $resolved['slack']);
        $this->assertSame('tenant', $resolved['source']);
    }

    public function test_resolver_partial_tenant_config_falls_back_per_channel(): void
    {
        TenantNotificationSetting::create([
            'tenant_id' => 'tenant-partial',
            'webhook_url' => 'https://tenant-partial.test/webhook',
            'slack_url' => null,
            'discord_url' => null,
            'enabled' => true,
        ]);

        $resolved = (new TenantNotificationResolver())->resolve('tenant-partial');

        $this->assertSame('https://tenant-partial.test/webhook', $resolved['webhook']);
        // null channels inherit the global config
        $this->assertSame('https://global.test/slack', $resolved['slack']);
        $this->assertSame('https://global.test/discord', $resolved['discord']);
    }

    public function test_resolver_disabled_tenant_suppresses_all_channels(): void
    {
        TenantNotificationSetting::create([
            'tenant_id' => 'tenant-off',
            'webhook_url' => 'https://tenant-off.test/webhook',
            'enabled' => false,
        ]);

        $resolved = (new TenantNotificationResolver())->resolve('tenant-off');

        $this->assertNull($resolved['webhook']);
        $this->assertNull($resolved['slack']);
        $this->assertNull($resolved['discord']);
        $this->assertSame('tenant_disabled', $resolved['source']);
        $this->assertFalse($resolved['enabled']);
    }

    public function test_resolver_url_for_single_channel(): void
    {
        TenantNotificationSetting::create([
            'tenant_id' => 'tenant-a',
            'webhook_url' => 'https://tenant-a.test/webhook',
            'enabled' => true,
        ]);

        $resolver = new TenantNotificationResolver();
        $this->assertSame('https://tenant-a.test/webhook', $resolver->urlFor('tenant-a', 'webhook'));
    }

    // =========================================================================
    // SocNotifier records tenant_id
    // =========================================================================

    public function test_notifier_records_tenant_id_in_delivery_log(): void
    {
        Http::fake(['https://tenant-a.test/*' => Http::response(['ok' => true], 200)]);

        (new SocNotifier())->send('webhook', 'https://tenant-a.test/webhook', 'sla_breach', 'INC-1', ['message' => 'x'], 'tenant-a');

        $this->assertDatabaseHas('notification_delivery_logs', [
            'incident_id' => 'INC-1',
            'tenant_id' => 'tenant-a',
            'status' => 'delivered',
        ]);
    }

    public function test_notifier_records_null_tenant_when_not_provided(): void
    {
        Http::fake(['https://global.test/*' => Http::response(['ok' => true], 200)]);

        (new SocNotifier())->send('webhook', 'https://global.test/webhook', 'sla_breach', 'INC-2', ['message' => 'x']);

        $this->assertDatabaseHas('notification_delivery_logs', [
            'incident_id' => 'INC-2',
            'tenant_id' => null,
            'status' => 'delivered',
        ]);
    }

    // =========================================================================
    // SLA escalation end-to-end routing
    // =========================================================================

    public function test_escalation_routes_to_tenant_specific_webhook(): void
    {
        Http::fake([
            'https://tenant-a.test/*' => Http::response(['ok' => true], 200),
            'https://global.test/*' => Http::response(['ok' => true], 200),
        ]);
        TenantNotificationSetting::create([
            'tenant_id' => 'tenant-a',
            'webhook_url' => 'https://tenant-a.test/webhook',
            'slack_url' => null,
            'discord_url' => null,
            'enabled' => true,
        ]);
        $this->insertIncident('INC-A', 'tenant-a');

        $this->artisan('soc:sla-escalate')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->url() === 'https://tenant-a.test/webhook');
        $this->assertDatabaseHas('notification_delivery_logs', [
            'incident_id' => 'INC-A',
            'tenant_id' => 'tenant-a',
            'target_type' => 'webhook',
            'status' => 'delivered',
        ]);
    }

    public function test_escalation_does_not_leak_tenant_a_targets_to_tenant_b(): void
    {
        Http::fake([
            'https://tenant-a.test/*' => Http::response(['ok' => true], 200),
            'https://global.test/*' => Http::response(['ok' => true], 200),
        ]);
        TenantNotificationSetting::create([
            'tenant_id' => 'tenant-a',
            'webhook_url' => 'https://tenant-a.test/webhook',
            'enabled' => true,
        ]);
        // tenant-b has no settings → must use global, never tenant-a's URL
        $this->insertIncident('INC-B', 'tenant-b');

        $this->artisan('soc:sla-escalate')->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'tenant-a.test'));
        Http::assertSent(fn ($request) => $request->url() === 'https://global.test/webhook');
        $this->assertDatabaseHas('notification_delivery_logs', [
            'incident_id' => 'INC-B',
            'tenant_id' => 'tenant-b',
        ]);
    }

    public function test_escalation_suppresses_delivery_for_disabled_tenant(): void
    {
        Http::fake();
        TenantNotificationSetting::create([
            'tenant_id' => 'tenant-off',
            'webhook_url' => 'https://tenant-off.test/webhook',
            'enabled' => false,
        ]);
        $this->insertIncident('INC-OFF', 'tenant-off');

        $this->artisan('soc:sla-escalate')->assertExitCode(0);

        // no HTTP delivery happened
        Http::assertNothingSent();
        // but the suppression was still audited, scoped to the tenant
        $this->assertDatabaseHas('notification_delivery_logs', [
            'incident_id' => 'INC-OFF',
            'tenant_id' => 'tenant-off',
            'status' => 'skipped',
        ]);
    }

    public function test_escalation_null_tenant_incident_uses_global(): void
    {
        Http::fake(['https://global.test/*' => Http::response(['ok' => true], 200)]);
        $this->insertIncident('INC-NULL', null);

        $this->artisan('soc:sla-escalate')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->url() === 'https://global.test/webhook');
        $this->assertDatabaseHas('notification_delivery_logs', [
            'incident_id' => 'INC-NULL',
            'tenant_id' => null,
            'status' => 'delivered',
        ]);
    }

    // =========================================================================
    // Critical-incident notification routing (soc:notify-critical)
    // =========================================================================

    public function test_notify_critical_routes_to_tenant_specific_webhook(): void
    {
        Http::fake([
            'https://tenant-a.test/*' => Http::response(['ok' => true], 200),
            'https://global.test/*' => Http::response(['ok' => true], 200),
        ]);
        TenantNotificationSetting::create([
            'tenant_id' => 'tenant-a',
            'webhook_url' => 'https://tenant-a.test/webhook',
            'enabled' => true,
        ]);

        DB::table('security_incidents')->insert([
            'incident_id' => 'INC-CRIT-A',
            'title' => 'Critical breach',
            'status' => 'open',
            'severity' => 'critical',
            'confidence' => 0.95,
            'tenant_id' => 'tenant-a',
            'first_seen_at' => now()->subMinutes(10),
            'last_seen_at' => now()->subMinutes(5),
            'sla_due_at' => now()->addHour(),
            'escalation_level' => 0,
            'affected_entities' => json_encode([]),
            'timeline' => json_encode([]),
            'mitre_mapping' => json_encode([]),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('soc:notify-critical')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->url() === 'https://tenant-a.test/webhook');
        $this->assertDatabaseHas('notification_delivery_logs', [
            'incident_id' => 'INC-CRIT-A',
            'tenant_id' => 'tenant-a',
            'event_type' => 'critical_incident',
            'status' => 'delivered',
        ]);
    }
}
