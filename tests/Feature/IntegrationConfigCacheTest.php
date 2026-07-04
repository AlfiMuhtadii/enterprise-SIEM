<?php

namespace Tests\Feature;

use App\Services\Integrations\JiraRealAdapter;
use App\Services\Integrations\PagerDutyRealAdapter;
use App\Services\Integrations\ServiceNowRealAdapter;
use App\Services\Integrations\SlackRealAdapter;
use Tests\TestCase;

/**
 * ENV-CACHE-DRIFT-BATCH — integration adapters + TrustProxies/force_https now read
 * config (config-cache-safe) rather than env() directly.
 */
class IntegrationConfigCacheTest extends TestCase
{
    public function test_integration_config_keys_exist(): void
    {
        foreach (['jira', 'pagerduty', 'servicenow', 'slack'] as $svc) {
            $this->assertIsArray(config("integrations.$svc"), "integrations.$svc missing");
            $this->assertArrayHasKey('dry_run', config("integrations.$svc"));
        }
        $this->assertNotNull(config('xdr.trusted_proxies'));
        $this->assertArrayHasKey('force_https', config('xdr'));
    }

    public function test_jira_adapter_reads_from_config(): void
    {
        config([
            'integrations.jira.url'       => 'https://jira.example',
            'integrations.jira.email'     => 'soc@example',
            'integrations.jira.api_token' => 'tok-123',
            'integrations.jira.dry_run'   => false,
        ]);
        $adapter = new JiraRealAdapter();
        $this->assertTrue($adapter->isConfigured());
    }

    public function test_unconfigured_adapters_are_not_configured(): void
    {
        config([
            'integrations.jira.url' => '', 'integrations.jira.email' => '', 'integrations.jira.api_token' => '',
            'integrations.slack.webhook_url' => '',
            'integrations.pagerduty.routing_key' => '',
            'integrations.servicenow.url' => '', 'integrations.servicenow.user' => '', 'integrations.servicenow.password' => '',
        ]);
        $this->assertFalse((new JiraRealAdapter())->isConfigured());
        $this->assertFalse((new SlackRealAdapter())->isConfigured());
        $this->assertFalse((new PagerDutyRealAdapter())->isConfigured());
        $this->assertFalse((new ServiceNowRealAdapter())->isConfigured());
    }

    public function test_migrated_files_have_no_direct_env_calls(): void
    {
        $files = [
            base_path('app/Services/Integrations/JiraRealAdapter.php'),
            base_path('app/Services/Integrations/PagerDutyRealAdapter.php'),
            base_path('app/Services/Integrations/ServiceNowRealAdapter.php'),
            base_path('app/Services/Integrations/SlackRealAdapter.php'),
            base_path('app/Http/Middleware/TrustProxies.php'),
            base_path('app/Providers/AppServiceProvider.php'),
        ];
        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression(
                "/env\(['\"]/",
                file_get_contents($file),
                basename($file).' should not call env() directly (config:cache bypass)'
            );
        }
    }

    public function test_cli_slice_reads_config_not_env(): void
    {
        // ENV-CACHE-DRIFT-BATCH (CLI slice): strict-mode preflight + shadow-consumer
        // gate now read config (config:cache-safe, aligned with the canonical source).
        $this->assertArrayHasKey('shadow_consumer_enabled', config('xdr'));

        $preflight = file_get_contents(base_path('app/Console/Commands/TenantBackfillPreflightCommand.php'));
        $this->assertStringNotContainsString("env('XDR_TENANT_STRICT_MODE'", $preflight);
        $this->assertStringContainsString("config('xdr.tenancy.strict_mode'", $preflight);

        $soak = file_get_contents(base_path('app/Services/EndpointSoakPlanService.php'));
        $this->assertStringNotContainsString("env('XDR_SHADOW_CONSUMER_ENABLED'", $soak);
        $this->assertStringContainsString("config('xdr.shadow_consumer_enabled'", $soak);
    }
}
