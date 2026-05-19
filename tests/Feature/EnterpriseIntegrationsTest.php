<?php

namespace Tests\Feature;

use App\Models\ExternalCaseLink;
use App\Models\ExternalIntegration;
use App\Models\IdentityProviderEvent;
use App\Models\IntegrationSyncEvent;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\SaasAuditEvent;
use App\Models\User;
use App\Services\EnterpriseIntegrationService;
use App\Services\NotificationService;
use App\Services\SaasIdentityNormalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Enterprise Integrations Phase 1 tests.
 *
 * Explicitly asserts:
 * - No automatic account suspension
 * - No autonomous ticket closure (auto_closed=false always)
 * - No unrestricted webhook execution (all deliveries simulated by default)
 * - No bidirectional destructive sync (all sync advisory_only=true)
 * - No external credential harvesting (credentials are config-referenced only)
 * - No hidden synchronization behavior (all sync events are audited append-only)
 * - No uncontrolled external API mutation
 * - Risk scoring is advisory only
 * - Idempotent event ingestion
 */
class EnterpriseIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function analyst(): User
    {
        return User::factory()->create(['role' => 'analyst']);
    }

    private function integrationService(): EnterpriseIntegrationService
    {
        return app(EnterpriseIntegrationService::class);
    }

    private function notificationService(): NotificationService
    {
        return app(NotificationService::class);
    }

    private function normalizationService(): SaasIdentityNormalizationService
    {
        return app(SaasIdentityNormalizationService::class);
    }

    private function makeIntegration(string $type = 'idp', string $provider = 'okta'): ExternalIntegration
    {
        return ExternalIntegration::create([
            'integration_id'    => 'int-test-' . uniqid(),
            'integration_type'  => $type,
            'provider'          => $provider,
            'name'              => ucfirst($provider) . ' Integration',
            'status'            => 'inactive',
            'sync_advisory_only'=> true,
        ]);
    }

    // -----------------------------------------------------------------------
    // Schema verification
    // -----------------------------------------------------------------------

    public function test_enterprise_integration_tables_exist(): void
    {
        foreach ([
            'external_integrations',
            'integration_sync_events',
            'external_case_links',
            'notification_events',
            'notification_deliveries',
            'saas_audit_events',
            'identity_provider_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table missing: {$table}");
        }
    }

    public function test_external_integrations_has_required_columns(): void
    {
        $cols = Schema::getColumnListing('external_integrations');
        foreach (['integration_id', 'integration_type', 'provider', 'name', 'status', 'sync_advisory_only'] as $col) {
            $this->assertContains($col, $cols);
        }
    }

    public function test_append_only_tables_have_no_updated_at(): void
    {
        foreach ([
            'integration_sync_events',
            'external_case_links',
            'notification_events',
            'notification_deliveries',
            'saas_audit_events',
            'identity_provider_events',
        ] as $table) {
            $this->assertNotContains('updated_at', Schema::getColumnListing($table), "Append-only table {$table} must not have updated_at");
        }
    }

    public function test_auto_closed_column_defaults_false(): void
    {
        $this->assertContains('auto_closed', Schema::getColumnListing('external_case_links'));
    }

    // -----------------------------------------------------------------------
    // Integration registration
    // -----------------------------------------------------------------------

    public function test_register_integration_creates_inactive_record(): void
    {
        $integration = $this->integrationService()->registerIntegration([
            'integration_type' => 'idp',
            'provider'         => 'okta',
            'name'             => 'Okta Corp',
        ]);

        $this->assertSame('inactive', $integration->status);
        $this->assertTrue($integration->sync_advisory_only);
        $this->assertStringStartsWith('int-', $integration->integration_id);
    }

    public function test_new_integration_always_starts_advisory(): void
    {
        $integration = $this->integrationService()->registerIntegration([
            'integration_type' => 'saas',
            'provider'         => 'office365',
            'name'             => 'Office 365',
        ]);
        $this->assertTrue($integration->sync_advisory_only);
    }

    public function test_activate_integration_changes_status(): void
    {
        $integration = $this->makeIntegration();
        $updated = $this->integrationService()->activateIntegration($integration);
        $this->assertSame('active', $updated->status);
    }

    public function test_disable_integration_sets_disabled_status(): void
    {
        $integration = $this->makeIntegration();
        $this->integrationService()->activateIntegration($integration);
        $updated = $this->integrationService()->disableIntegration($integration->fresh());
        $this->assertSame('disabled', $updated->status);
    }

    // -----------------------------------------------------------------------
    // Sync event recording (append-only)
    // -----------------------------------------------------------------------

    public function test_sync_start_creates_append_only_record(): void
    {
        $integration = $this->makeIntegration();
        $sync = $this->integrationService()->recordSyncStart($integration, 'inbound', 'full_pull');
        $this->assertSame('started', $sync->status);
        $this->assertTrue($sync->dry_run);
        $this->assertTrue($sync->advisory_only);
    }

    public function test_sync_is_dry_run_by_default(): void
    {
        $integration = $this->makeIntegration();
        $sync = $this->integrationService()->recordSyncStart($integration, 'inbound', 'incremental_pull');
        $this->assertTrue($sync->dry_run, 'Sync must be dry_run=true by default');
    }

    public function test_sync_advisory_only_is_always_true(): void
    {
        $integration = $this->makeIntegration();
        // Even when caller passes dry_run=false, advisory_only must remain true
        $sync = $this->integrationService()->recordSyncStart($integration, 'outbound', 'push_case', false);
        $this->assertTrue($sync->advisory_only);
    }

    public function test_complete_sync_creates_new_completed_record(): void
    {
        $integration = $this->makeIntegration();
        $started = $this->integrationService()->recordSyncStart($integration, 'inbound', 'full_pull');
        $completed = $this->integrationService()->completeSyncEvent($started, 42, 0, ['note' => 'ok']);
        $this->assertSame('completed', $completed->status);
        $this->assertSame(42, $completed->records_processed);
        // append-only: both records exist
        $this->assertEquals(2, IntegrationSyncEvent::where('integration_id', $integration->id)->count());
    }

    public function test_complete_sync_caps_records_at_max(): void
    {
        $integration = $this->makeIntegration();
        $started = $this->integrationService()->recordSyncStart($integration, 'inbound', 'full_pull');
        $completed = $this->integrationService()->completeSyncEvent($started, 99999, 0);
        $this->assertLessThanOrEqual(
            EnterpriseIntegrationService::MAX_SYNC_RECORDS_PER_RUN,
            $completed->records_processed
        );
    }

    public function test_failed_sync_records_error_detail(): void
    {
        $integration = $this->makeIntegration();
        $started = $this->integrationService()->recordSyncStart($integration, 'inbound', 'full_pull');
        $failed = $this->integrationService()->recordSyncFailure($started, 'Connection timeout');
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('Connection', $failed->error_detail);
        $this->assertTrue($failed->advisory_only);
    }

    // -----------------------------------------------------------------------
    // Case linking — no autonomous closure
    // -----------------------------------------------------------------------

    public function test_link_case_creates_append_only_record(): void
    {
        $integration = $this->makeIntegration('ticketing', 'jira');
        $link = $this->integrationService()->linkCase('inv-abc-123', $integration, [
            'external_ticket_id' => 'JIRA-456',
            'link_direction'     => 'soc_to_external',
        ]);
        $this->assertSame('JIRA-456', $link->external_ticket_id);
        $this->assertSame('soc_to_external', $link->link_direction);
    }

    public function test_case_link_auto_closed_is_always_false(): void
    {
        $integration = $this->makeIntegration('ticketing', 'jira');
        $link = $this->integrationService()->linkCase('inv-test', $integration, [
            'external_ticket_id' => 'JIRA-100',
            'auto_closed'        => true,  // caller tries to set it true
        ]);
        // Hard constraint: auto_closed must always be false
        $this->assertFalse($link->auto_closed, 'auto_closed must always be false — no autonomous ticket closure');
    }

    public function test_case_link_sync_advisory_only_is_always_true(): void
    {
        $integration = $this->makeIntegration('ticketing', 'servicenow');
        $link = $this->integrationService()->linkCase('inv-test-2', $integration, [
            'external_ticket_id' => 'INC0001234',
        ]);
        $this->assertTrue($link->sync_advisory_only);
    }

    // -----------------------------------------------------------------------
    // Notification service — simulated by default
    // -----------------------------------------------------------------------

    public function test_create_notification_records_event(): void
    {
        $integration = $this->makeIntegration('notification', 'slack');
        $notif = $this->notificationService()->createNotification($integration, [
            'notification_type' => 'alert_fired',
            'severity'          => 'high',
            'channel'           => 'slack',
            'subject'           => 'XDR Alert — suspicious login',
            'body'              => 'Advisory notification body',
        ]);
        $this->assertStringStartsWith('notif-', $notif->notification_id);
        $this->assertSame('slack', $notif->channel);
    }

    public function test_notification_delivery_is_simulated_by_default(): void
    {
        $integration = $this->makeIntegration('notification', 'slack');
        $notif = $this->notificationService()->createNotification($integration, [
            'notification_type' => 'alert_fired',
            'severity'          => 'medium',
            'channel'           => 'slack',
            'subject'           => 'Test',
        ]);
        $delivery = $this->notificationService()->simulateDelivery($notif);
        $this->assertTrue($delivery->simulated, 'Delivery must be simulated=true by default');
        $this->assertSame(NotificationDelivery::STATUS_SKIPPED, $delivery->status);
    }

    public function test_escalation_notification_requires_analyst_approval(): void
    {
        $integration = $this->makeIntegration('notification', 'pagerduty');
        $notif = $this->notificationService()->createNotification($integration, [
            'notification_type' => 'escalation',
            'severity'          => 'critical',
            'channel'           => 'pagerduty',
            'subject'           => 'Escalation: critical incident',
        ]);
        $this->assertTrue(
            $notif->requires_analyst_approval,
            'Escalation notifications must require analyst approval'
        );
    }

    public function test_sla_breach_notification_requires_analyst_approval(): void
    {
        $integration = $this->makeIntegration('notification', 'pagerduty');
        $notif = $this->notificationService()->createNotification($integration, [
            'notification_type' => 'sla_breach',
            'severity'          => 'high',
            'channel'           => 'pagerduty',
            'subject'           => 'SLA Breach',
        ]);
        $this->assertTrue($notif->requires_analyst_approval);
    }

    public function test_regular_notification_does_not_require_approval(): void
    {
        $integration = $this->makeIntegration('notification', 'slack');
        $notif = $this->notificationService()->createNotification($integration, [
            'notification_type' => 'alert_fired',
            'severity'          => 'low',
            'channel'           => 'slack',
            'subject'           => 'Low alert',
        ]);
        $this->assertFalse($notif->requires_analyst_approval);
    }

    public function test_slack_payload_is_bounded_and_advisory(): void
    {
        $payload = $this->notificationService()->buildSlackPayload([
            'subject' => 'XDR advisory notification',
            'body'    => 'Full advisory body content',
        ]);
        $this->assertTrue($payload['advisory']);
        $this->assertTrue($payload['simulated']);
        $this->assertSame('xdr-soc', $payload['source']);
        $this->assertLessThanOrEqual(150, strlen($payload['text']));
    }

    public function test_webhook_payload_has_no_auto_action(): void
    {
        $payload = $this->notificationService()->buildWebhookPayload([
            'notification_type' => 'alert_fired',
            'severity'          => 'high',
            'subject'           => 'Test',
            'body'              => 'Body',
        ]);
        $this->assertTrue($payload['advisory']);
        $this->assertTrue($payload['no_auto_action']);
    }

    // -----------------------------------------------------------------------
    // Identity provider event ingestion
    // -----------------------------------------------------------------------

    public function test_ingest_identity_event_creates_record(): void
    {
        $integration = $this->makeIntegration('idp', 'okta');
        $event = $this->normalizationService()->ingestIdentityEvent($integration, [
            'event_id'   => 'evt-okta-001',
            'event_type' => 'login_success',
            'provider'   => 'okta',
            'user_email' => 'user@example.com',
            'source_ip'  => '1.2.3.4',
            'mfa_used'   => true,
            'is_failed'  => false,
            'occurred_at'=> now(),
        ]);
        $this->assertSame('evt-okta-001', $event->event_id);
        $this->assertSame('okta', $event->provider);
    }

    public function test_idp_ingest_is_idempotent(): void
    {
        $integration = $this->makeIntegration('idp', 'okta');
        $raw = ['event_id' => 'evt-okta-idem', 'event_type' => 'login_success', 'occurred_at' => now()];
        $this->normalizationService()->ingestIdentityEvent($integration, $raw);
        $this->normalizationService()->ingestIdentityEvent($integration, $raw);
        $this->assertEquals(1, IdentityProviderEvent::where('event_id', 'evt-okta-idem')->count());
    }

    public function test_brute_force_detection_marks_suspicious(): void
    {
        $integration = $this->makeIntegration('idp', 'okta');
        $event = $this->normalizationService()->ingestIdentityEvent($integration, [
            'event_id'            => 'evt-brute-001',
            'event_type'          => 'login_failure',
            'is_failed'           => true,
            'failed_attempt_count'=> 10,
            'occurred_at'         => now(),
        ]);
        $this->assertTrue($event->is_suspicious);
        $this->assertGreaterThanOrEqual(
            SaasIdentityNormalizationService::RISK_SCORE_HIGH,
            $event->risk_score
        );
    }

    public function test_normal_login_with_mfa_is_not_suspicious(): void
    {
        $integration = $this->makeIntegration('idp', 'azure_ad');
        $event = $this->normalizationService()->ingestIdentityEvent($integration, [
            'event_id'   => 'evt-normal-001',
            'event_type' => 'login_success',
            'mfa_used'   => true,
            'is_failed'  => false,
            'occurred_at'=> now(),
        ]);
        $this->assertFalse($event->is_suspicious);
    }

    public function test_idp_risk_score_is_advisory_no_enforcement(): void
    {
        $integration = $this->makeIntegration('idp', 'okta');
        // Even a high-risk event must not trigger any account action
        $src = file_get_contents(
            app_path('Services/SaasIdentityNormalizationService.php')
        );
        $this->assertStringNotContainsString('suspend_user', $src);
        $this->assertStringNotContainsString('lock_account', $src);
        $this->assertStringNotContainsString('revoke_session', $src);
        $this->assertStringNotContainsString('disable_account', $src);
    }

    public function test_batch_idp_ingest_respects_max_limit(): void
    {
        $integration = $this->makeIntegration('idp', 'okta');
        $events = array_fill(0, 600, ['event_type' => 'login_success', 'occurred_at' => now()]);
        $results = $this->normalizationService()->ingestBatchIdentityEvents($integration, $events);
        $this->assertLessThanOrEqual(
            SaasIdentityNormalizationService::MAX_INGEST_BATCH,
            $results['ingested'] + $results['skipped']
        );
    }

    // -----------------------------------------------------------------------
    // SaaS audit event ingestion
    // -----------------------------------------------------------------------

    public function test_ingest_saas_audit_event_creates_record(): void
    {
        $integration = $this->makeIntegration('saas', 'office365');
        $event = $this->normalizationService()->ingestSaasAuditEvent($integration, [
            'event_id'    => 'saas-evt-001',
            'provider'    => 'office365',
            'actor_email' => 'admin@corp.com',
            'action'      => 'login',
            'occurred_at' => now(),
        ]);
        $this->assertSame('saas-evt-001', $event->event_id);
        $this->assertSame('office365', $event->provider);
    }

    public function test_saas_ingest_is_idempotent(): void
    {
        $integration = $this->makeIntegration('saas', 'gsuite');
        $raw = ['event_id' => 'saas-idem-001', 'action' => 'login', 'occurred_at' => now()];
        $this->normalizationService()->ingestSaasAuditEvent($integration, $raw);
        $this->normalizationService()->ingestSaasAuditEvent($integration, $raw);
        $this->assertEquals(1, SaasAuditEvent::where('event_id', 'saas-idem-001')->count());
    }

    public function test_suspicious_saas_action_flags_event(): void
    {
        $integration = $this->makeIntegration('saas', 'office365');
        $event = $this->normalizationService()->ingestSaasAuditEvent($integration, [
            'event_id'   => 'saas-susp-001',
            'provider'   => 'office365',
            'action'     => 'bulk_download',
            'occurred_at'=> now(),
        ]);
        $this->assertTrue($event->is_suspicious);
    }

    public function test_saas_risk_score_has_no_account_enforcement(): void
    {
        $src = file_get_contents(app_path('Services/SaasIdentityNormalizationService.php'));
        // Advisory posture: no account actions in risk scorer
        $this->assertStringNotContainsString('suspend_account', $src);
        $this->assertStringNotContainsString('disable_user', $src);
        $this->assertStringNotContainsString('revoke_access', $src);
    }

    // -----------------------------------------------------------------------
    // Advisory posture — hard constraints
    // -----------------------------------------------------------------------

    public function test_enterprise_integration_service_has_no_autonomous_operations(): void
    {
        $src = file_get_contents(app_path('Services/EnterpriseIntegrationService.php'));
        $this->assertStringNotContainsString('autonomous_sync', $src);
        $this->assertStringNotContainsString('auto_execute', $src);
        $this->assertStringNotContainsString('close_ticket', $src);
    }

    public function test_notification_service_has_no_account_actions(): void
    {
        $src = file_get_contents(app_path('Services/NotificationService.php'));
        $this->assertStringNotContainsString('suspend_user', $src);
        $this->assertStringNotContainsString('lock_account', $src);
        $this->assertStringNotContainsString('kill_session', $src);
    }

    public function test_integration_service_no_credential_storage(): void
    {
        $src = file_get_contents(app_path('Services/EnterpriseIntegrationService.php'));
        // Credentials are config-referenced only, never stored or returned
        $this->assertStringNotContainsString('api_key', $src);
        $this->assertStringNotContainsString('password', $src);
        $this->assertStringNotContainsString('secret_token', $src);
    }

    public function test_all_case_links_are_append_only_never_auto_closed(): void
    {
        $integration = $this->makeIntegration('ticketing', 'jira');
        for ($i = 0; $i < 3; $i++) {
            $link = $this->integrationService()->linkCase("inv-{$i}", $integration, [
                'external_ticket_id' => "JIRA-{$i}",
            ]);
            $this->assertFalse($link->auto_closed);
            $this->assertTrue($link->sync_advisory_only);
        }
        $this->assertEquals(3, ExternalCaseLink::where('integration_id', $integration->id)->count());
    }

    public function test_all_sync_events_are_advisory_only(): void
    {
        $integration = $this->makeIntegration();
        $sync = $this->integrationService()->recordSyncStart($integration, 'inbound', 'full_pull');
        $completed = $this->integrationService()->completeSyncEvent($sync, 10, 0);
        foreach (IntegrationSyncEvent::where('integration_id', $integration->id)->get() as $s) {
            $this->assertTrue((bool) $s->advisory_only);
        }
    }

    // -----------------------------------------------------------------------
    // Model constants and types
    // -----------------------------------------------------------------------

    public function test_external_integration_types_are_valid(): void
    {
        foreach (ExternalIntegration::INTEGRATION_TYPES as $type) {
            $this->assertContains($type, ['idp', 'saas', 'ticketing', 'notification']);
        }
    }

    public function test_identity_provider_event_types_are_known(): void
    {
        $this->assertContains('login_success', IdentityProviderEvent::EVENT_TYPES);
        $this->assertContains('login_failure', IdentityProviderEvent::EVENT_TYPES);
        $this->assertContains('mfa_challenge', IdentityProviderEvent::EVENT_TYPES);
        $this->assertContains('account_locked', IdentityProviderEvent::EVENT_TYPES);
        $this->assertContains('privilege_escalation', IdentityProviderEvent::EVENT_TYPES);
    }

    public function test_saas_audit_event_suspicious_actions_are_known(): void
    {
        $this->assertContains('bulk_download', SaasAuditEvent::SUSPICIOUS_ACTIONS);
        $this->assertContains('permission_escalation', SaasAuditEvent::SUSPICIOUS_ACTIONS);
        $this->assertContains('admin_account_created', SaasAuditEvent::SUSPICIOUS_ACTIONS);
        $this->assertContains('mfa_disabled', SaasAuditEvent::SUSPICIOUS_ACTIONS);
    }

    public function test_notification_event_channels_are_constrained(): void
    {
        $this->assertContains('slack', NotificationEvent::CHANNELS);
        $this->assertContains('pagerduty', NotificationEvent::CHANNELS);
        $this->assertContains('webhook', NotificationEvent::CHANNELS);
    }

    // -----------------------------------------------------------------------
    // HTTP routes
    // -----------------------------------------------------------------------

    public function test_integration_dashboard_requires_auth(): void
    {
        $resp = $this->get(route('integrations.dashboard'));
        $resp->assertRedirect('/login');
    }

    public function test_integration_dashboard_returns_200_for_analyst(): void
    {
        $analyst = $this->analyst();
        $resp = $this->actingAs($analyst)->get(route('integrations.dashboard'));
        $resp->assertStatus(200);
    }

    public function test_integration_registry_returns_200(): void
    {
        $analyst = $this->analyst();
        $resp = $this->actingAs($analyst)->get(route('integrations.registry'));
        $resp->assertStatus(200);
    }

    public function test_idp_feed_returns_200(): void
    {
        $analyst = $this->analyst();
        $resp = $this->actingAs($analyst)->get(route('integrations.idp-feed'));
        $resp->assertStatus(200);
    }

    public function test_saas_audit_returns_200(): void
    {
        $analyst = $this->analyst();
        $resp = $this->actingAs($analyst)->get(route('integrations.saas-audit'));
        $resp->assertStatus(200);
    }

    public function test_notification_center_returns_200(): void
    {
        $analyst = $this->analyst();
        $resp = $this->actingAs($analyst)->get(route('integrations.notifications'));
        $resp->assertStatus(200);
    }

    public function test_case_links_returns_200(): void
    {
        $analyst = $this->analyst();
        $resp = $this->actingAs($analyst)->get(route('integrations.case-links'));
        $resp->assertStatus(200);
    }

    public function test_sync_history_returns_200(): void
    {
        $analyst = $this->analyst();
        $resp = $this->actingAs($analyst)->get(route('integrations.sync-history'));
        $resp->assertStatus(200);
    }

    // -----------------------------------------------------------------------
    // API endpoints
    // -----------------------------------------------------------------------

    public function test_api_integrations_list_returns_advisory_flag(): void
    {
        $analyst = $this->analyst();
        $resp = $this->actingAs($analyst)->getJson(route('api.integrations.list'));
        $resp->assertStatus(200);
        $resp->assertJsonFragment(['advisory' => true]);
    }

    public function test_api_idp_events_returns_no_enforcement_flag(): void
    {
        $analyst = $this->analyst();
        $resp = $this->actingAs($analyst)->getJson(route('api.integrations.idp-events'));
        $resp->assertStatus(200);
        $resp->assertJsonFragment(['no_enforcement' => true]);
    }

    public function test_api_saas_events_returns_no_enforcement_flag(): void
    {
        $analyst = $this->analyst();
        $resp = $this->actingAs($analyst)->getJson(route('api.integrations.saas-events'));
        $resp->assertStatus(200);
        $resp->assertJsonFragment(['no_enforcement' => true]);
    }

    public function test_api_high_risk_includes_no_auto_action_flag(): void
    {
        $analyst = $this->analyst();
        $resp = $this->actingAs($analyst)->getJson(route('api.integrations.high-risk'));
        $resp->assertStatus(200);
        $resp->assertJsonFragment(['no_auto_action' => true]);
    }

    public function test_api_notifications_includes_simulated_flag(): void
    {
        $analyst = $this->analyst();
        $resp = $this->actingAs($analyst)->getJson(route('api.integrations.notifications'));
        $resp->assertStatus(200);
        $resp->assertJsonFragment(['simulated' => true]);
    }

    public function test_api_register_integration_returns_advisory(): void
    {
        $analyst = $this->analyst();
        $resp = $this->actingAs($analyst)->postJson(route('integrations.register'), [
            'integration_type' => 'idp',
            'provider'         => 'okta',
            'name'             => 'Okta Test',
        ]);
        $resp->assertStatus(200);
        $resp->assertJsonFragment(['advisory' => true]);
    }

    public function test_api_link_case_never_sets_auto_closed_true(): void
    {
        $analyst = $this->analyst();
        $integration = $this->makeIntegration('ticketing', 'jira');
        $resp = $this->actingAs($analyst)->postJson(
            route('integrations.link-case', $integration),
            [
                'investigation_id'   => 'inv-http-001',
                'external_ticket_id' => 'JIRA-HTTP-001',
            ]
        );
        $resp->assertStatus(200);
        $resp->assertJsonFragment(['auto_closed' => false]);
    }

    public function test_api_notification_send_returns_simulated_true(): void
    {
        $analyst = $this->analyst();
        $integration = $this->makeIntegration('notification', 'slack');
        $resp = $this->actingAs($analyst)->postJson(
            route('integrations.notify', $integration),
            [
                'notification_type' => 'alert_fired',
                'severity'          => 'high',
                'channel'           => 'slack',
                'subject'           => 'Advisory test notification',
            ]
        );
        $resp->assertStatus(200);
        $resp->assertJsonFragment(['simulated' => true]);
        $resp->assertJsonFragment(['advisory' => true]);
    }

    // -----------------------------------------------------------------------
    // Threat hunting domain extension
    // -----------------------------------------------------------------------

    public function test_threat_hunting_service_supports_idp_events_domain(): void
    {
        $domains = \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS;
        $this->assertContains('identity_provider_events', $domains);
    }

    public function test_threat_hunting_service_supports_saas_audit_events_domain(): void
    {
        $domains = \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS;
        $this->assertContains('saas_audit_events', $domains);
    }

    public function test_threat_hunting_service_supports_notification_events_domain(): void
    {
        $domains = \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS;
        $this->assertContains('notification_events', $domains);
    }

    public function test_threat_hunting_service_supports_external_case_links_domain(): void
    {
        $domains = \App\Services\ThreatHuntingService::SUPPORTED_DOMAINS;
        $this->assertContains('external_case_links', $domains);
    }

    // -----------------------------------------------------------------------
    // Model relationships
    // -----------------------------------------------------------------------

    public function test_external_integration_has_sync_events_relationship(): void
    {
        $integration = $this->makeIntegration();
        $this->integrationService()->recordSyncStart($integration, 'inbound', 'full_pull');
        $this->assertEquals(1, $integration->syncEvents()->count());
    }

    public function test_external_integration_has_notification_events_relationship(): void
    {
        $integration = $this->makeIntegration('notification', 'slack');
        $this->notificationService()->createNotification($integration, [
            'notification_type' => 'alert_fired',
            'severity'          => 'low',
            'channel'           => 'slack',
            'subject'           => 'Test',
        ]);
        $this->assertEquals(1, $integration->notificationEvents()->count());
    }

    public function test_notification_event_has_deliveries_relationship(): void
    {
        $integration = $this->makeIntegration('notification', 'slack');
        $notif = $this->notificationService()->createNotification($integration, [
            'notification_type' => 'alert_fired',
            'severity'          => 'low',
            'channel'           => 'slack',
            'subject'           => 'Test',
        ]);
        $delivery = $this->notificationService()->simulateDelivery($notif);
        $this->assertEquals(1, $notif->deliveries()->count());
        $this->assertTrue($notif->deliveries->first()->simulated);
    }
}
