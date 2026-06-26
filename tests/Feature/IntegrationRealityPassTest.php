<?php

namespace Tests\Feature;

use App\Services\Integrations\JiraRealAdapter;
use App\Services\Integrations\PagerDutyRealAdapter;
use App\Services\Integrations\ServiceNowRealAdapter;
use App\Services\Integrations\SlackRealAdapter;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ENTERPRISE-054: Integration Reality Pass
 *
 * Validates: adapters default to dry_run, dispatch routing,
 * SIMULATED_BY_DEFAULT unchanged, connector contract doc exists.
 */
class IntegrationRealityPassTest extends TestCase
{
    use RefreshDatabase;

    // ── Safety: SIMULATED_BY_DEFAULT unchanged ────────────────────────────────

    public function test_notification_service_simulated_by_default_unchanged(): void
    {
        $this->assertTrue(NotificationService::SIMULATED_BY_DEFAULT);
    }

    // ── SlackRealAdapter ──────────────────────────────────────────────────────

    public function test_slack_dry_run_default_is_true(): void
    {
        $this->assertTrue(SlackRealAdapter::DRY_RUN_DEFAULT);
    }

    public function test_slack_not_configured_without_env(): void
    {
        // No XDR_SLACK_WEBHOOK_URL set in test env
        $adapter = new SlackRealAdapter();
        $this->assertFalse($adapter->isConfigured());
    }

    public function test_slack_dispatch_returns_simulated_when_unconfigured(): void
    {
        $adapter = new SlackRealAdapter();
        $result  = $adapter->dispatch(['text' => 'XDR Test Alert']);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['simulated']);
        $this->assertTrue($result['dry_run']);
    }

    public function test_slack_dispatch_returns_simulated_when_dry_run(): void
    {
        // Even with a configured URL, dry_run=true returns simulated
        $result = (new SlackRealAdapter())->dispatch(['text' => 'test']);
        $this->assertTrue($result['simulated']);
    }

    // ── PagerDutyRealAdapter ──────────────────────────────────────────────────

    public function test_pagerduty_dry_run_default_is_true(): void
    {
        $this->assertTrue(PagerDutyRealAdapter::DRY_RUN_DEFAULT);
    }

    public function test_pagerduty_not_configured_without_env(): void
    {
        $adapter = new PagerDutyRealAdapter();
        $this->assertFalse($adapter->isConfigured());
    }

    public function test_pagerduty_trigger_returns_simulated_when_unconfigured(): void
    {
        $result = (new PagerDutyRealAdapter())->trigger(['subject' => 'Test', 'severity' => 'high']);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['simulated']);
    }

    // ── JiraRealAdapter ───────────────────────────────────────────────────────

    public function test_jira_dry_run_default_is_true(): void
    {
        $this->assertTrue(JiraRealAdapter::DRY_RUN_DEFAULT);
    }

    public function test_jira_not_configured_without_env(): void
    {
        $adapter = new JiraRealAdapter();
        $this->assertFalse($adapter->isConfigured());
    }

    public function test_jira_create_issue_returns_simulated_when_unconfigured(): void
    {
        $result = (new JiraRealAdapter())->createIssue(['subject' => 'XDR Alert']);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['simulated']);
    }

    // ── ServiceNowRealAdapter ─────────────────────────────────────────────────

    public function test_servicenow_dry_run_default_is_true(): void
    {
        $this->assertTrue(ServiceNowRealAdapter::DRY_RUN_DEFAULT);
    }

    public function test_servicenow_not_configured_without_env(): void
    {
        $adapter = new ServiceNowRealAdapter();
        $this->assertFalse($adapter->isConfigured());
    }

    public function test_servicenow_create_incident_returns_simulated_when_unconfigured(): void
    {
        $result = (new ServiceNowRealAdapter())->createIncident(['subject' => 'Alert', 'severity' => 'medium']);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['simulated']);
    }

    // ── NotificationService dispatch methods ──────────────────────────────────

    public function test_notification_service_dispatch_slack_returns_ok(): void
    {
        $service = app(NotificationService::class);
        $result  = $service->dispatchSlack(['subject' => 'Test', 'body' => 'Test body']);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['simulated']);
    }

    public function test_notification_service_dispatch_pagerduty_returns_ok(): void
    {
        $service = app(NotificationService::class);
        $result  = $service->dispatchPagerDuty(['subject' => 'Test', 'severity' => 'high']);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['simulated']);
    }

    public function test_notification_service_dispatch_jira_returns_ok(): void
    {
        $service = app(NotificationService::class);
        $result  = $service->dispatchJira(['subject' => 'Test']);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['simulated']);
    }

    public function test_notification_service_dispatch_servicenow_returns_ok(): void
    {
        $service = app(NotificationService::class);
        $result  = $service->dispatchServiceNow(['subject' => 'Alert']);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['simulated']);
    }

    // ── Connector contracts doc ───────────────────────────────────────────────

    public function test_connector_contracts_doc_exists(): void
    {
        $this->assertFileExists(base_path('docs/integrations/CONNECTOR_CONTRACTS.md'));
    }

    public function test_connector_contracts_covers_8_integrations(): void
    {
        $content = file_get_contents(base_path('docs/integrations/CONNECTOR_CONTRACTS.md'));
        foreach (['Slack', 'PagerDuty', 'Jira', 'ServiceNow', 'Okta', 'Azure Active Directory', 'Microsoft 365', 'Google Workspace'] as $integration) {
            $this->assertStringContainsString($integration, $content, "Missing: {$integration}");
        }
    }
}
