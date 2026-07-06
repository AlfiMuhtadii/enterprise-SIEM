<?php

namespace Tests\Feature;

use App\Contracts\SecretProvider;
use App\Models\SecurityHardeningEvent;
use App\Services\InternalAuthService;
use App\Services\Secrets\EnvSecretProvider;
use App\Services\Secrets\SecretProviderManager;
use App\Services\Secrets\VaultSecretProvider;
use App\Services\SecretsValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecretsVaultTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('SIEM_TEST_SECRET_KEY');
        parent::tearDown();
    }

    // =========================================================================
    // EnvSecretProvider
    // =========================================================================

    public function test_env_provider_returns_getenv_value(): void
    {
        putenv('SIEM_TEST_SECRET_KEY=from-env');
        $provider = new EnvSecretProvider();
        $this->assertSame('from-env', $provider->get('SIEM_TEST_SECRET_KEY', 'default'));
    }

    public function test_env_provider_returns_default_when_unset(): void
    {
        putenv('SIEM_TEST_SECRET_KEY');
        $provider = new EnvSecretProvider();
        $this->assertSame('default', $provider->get('SIEM_TEST_SECRET_KEY', 'default'));
    }

    public function test_env_provider_set_always_returns_false(): void
    {
        $provider = new EnvSecretProvider();
        $this->assertFalse($provider->set('ANY_KEY', 'value'));
    }

    public function test_env_provider_driver_name(): void
    {
        $this->assertSame('env', (new EnvSecretProvider())->driverName());
    }

    // =========================================================================
    // VaultSecretProvider
    // =========================================================================

    public function test_vault_provider_returns_default_when_not_configured(): void
    {
        $provider = new VaultSecretProvider('', '', '');
        $this->assertSame('fallback', $provider->get('ANY_KEY', 'fallback'));
    }

    public function test_vault_provider_reads_kv_v2_value(): void
    {
        Http::fake(['*' => Http::response(['data' => ['data' => ['ANY_KEY' => 'vault-value']]], 200)]);
        $provider = new VaultSecretProvider('https://vault.test', 'root-token', 'secret/data/xdr');

        $this->assertSame('vault-value', $provider->get('ANY_KEY', 'fallback'));
    }

    public function test_vault_provider_returns_default_on_missing_key(): void
    {
        Http::fake(['*' => Http::response(['data' => ['data' => []]], 200)]);
        $provider = new VaultSecretProvider('https://vault.test', 'root-token', 'secret/data/xdr');

        $this->assertSame('fallback', $provider->get('ANY_KEY', 'fallback'));
    }

    public function test_vault_provider_returns_default_on_failure_response(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);
        $provider = new VaultSecretProvider('https://vault.test', 'root-token', 'secret/data/xdr');

        $this->assertSame('fallback', $provider->get('ANY_KEY', 'fallback'));
    }

    public function test_vault_provider_returns_default_on_connection_exception(): void
    {
        Http::fake(['*' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('refused');
        }]);
        $provider = new VaultSecretProvider('https://vault.test', 'root-token', 'secret/data/xdr');

        $this->assertSame('fallback', $provider->get('ANY_KEY', 'fallback'));
    }

    public function test_vault_provider_set_merges_and_writes(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['data' => ['data' => ['EXISTING_KEY' => 'existing-value']]], 200)
                ->push(['data' => ['version' => 2]], 200),
        ]);
        $provider = new VaultSecretProvider('https://vault.test', 'root-token', 'secret/data/xdr');

        $this->assertTrue($provider->set('NEW_KEY', 'new-value'));
    }

    public function test_vault_provider_set_returns_false_when_not_configured(): void
    {
        $provider = new VaultSecretProvider('', '', '');
        $this->assertFalse($provider->set('ANY_KEY', 'value'));
    }

    public function test_vault_provider_is_reachable(): void
    {
        Http::fake(['*/v1/sys/health' => Http::response(['initialized' => true], 200)]);
        $provider = new VaultSecretProvider('https://vault.test', 'root-token', 'secret/data/xdr');

        $this->assertTrue($provider->isReachable());
    }

    public function test_vault_provider_not_reachable_on_failure(): void
    {
        Http::fake(['*/v1/sys/health' => Http::response(null, 500)]);
        $provider = new VaultSecretProvider('https://vault.test', 'root-token', 'secret/data/xdr');

        $this->assertFalse($provider->isReachable());
    }

    // =========================================================================
    // SecretProviderManager
    // =========================================================================

    public function test_manager_defaults_to_env_backend(): void
    {
        $manager = new SecretProviderManager('env');
        $this->assertSame('env', $manager->driverName());
    }

    public function test_manager_resolves_vault_backend(): void
    {
        $manager = new SecretProviderManager('vault');
        $this->assertSame('vault', $manager->driverName());
    }

    public function test_manager_unknown_backend_falls_back_to_env(): void
    {
        $manager = new SecretProviderManager('nonexistent-backend');
        $this->assertSame('env', $manager->driverName());
    }

    public function test_manager_is_bound_to_interface(): void
    {
        $this->assertInstanceOf(SecretProvider::class, app(SecretProvider::class));
    }

    // =========================================================================
    // InternalAuthService integration — provider is a real fallback layer
    // =========================================================================

    public function test_internal_auth_falls_back_to_provider_when_config_empty(): void
    {
        config([
            'xdr.internal_auth_secret' => '',
            'secrets.backend' => 'vault',
            'secrets.vault.addr' => 'https://vault.test',
            'secrets.vault.token' => 'root-token',
            'secrets.vault.secret_path' => 'secret/data/xdr',
        ]);
        $this->app->forgetInstance(SecretProvider::class);
        $this->app->singleton(SecretProvider::class, fn () => new SecretProviderManager());

        Http::fake(['*' => Http::response(['data' => ['data' => ['XDR_INTERNAL_AUTH_SECRET' => 'vault-backed-secret']]], 200)]);

        $token = InternalAuthService::signToken('svc-vault-test', time());
        $this->assertTrue(InternalAuthService::verifyToken($token));
    }

    public function test_internal_auth_prefers_config_over_provider(): void
    {
        config([
            'xdr.internal_auth_secret' => 'config-secret-value-xxxxxxxxxxxxxx',
            'secrets.backend' => 'vault',
        ]);
        Http::fake(['*' => Http::response(['data' => ['data' => ['XDR_INTERNAL_AUTH_SECRET' => 'vault-secret-should-not-be-used']]], 200)]);

        $token = InternalAuthService::signToken('svc-priority-test', time());
        $this->assertTrue(InternalAuthService::verifyToken($token));

        // Prove the config value (not the vault value) was actually used.
        config(['xdr.internal_auth_secret' => 'different-secret-value-xxxxxxxxx']);
        $this->assertFalse(InternalAuthService::verifyToken($token));
    }

    // =========================================================================
    // SecretsValidationService
    // =========================================================================

    public function test_validate_reports_env_backend_by_default(): void
    {
        config(['secrets.backend' => 'env']);
        $result = app(SecretsValidationService::class)->validate();
        $this->assertSame('env', $result['secret_backend']);
    }

    public function test_validate_warns_when_vault_backend_unreachable(): void
    {
        config([
            'secrets.backend' => 'vault',
            'secrets.vault.addr' => 'https://vault.test',
            'secrets.vault.token' => 'root-token',
            'secrets.vault.secret_path' => 'secret/data/xdr',
        ]);
        Http::fake(['*' => Http::response(null, 500)]);

        $result = app(SecretsValidationService::class)->validate();

        $this->assertTrue(collect($result['warnings'])->contains(fn ($w) => str_contains($w, 'Vault is unreachable')));
    }

    public function test_validate_no_vault_warning_when_reachable(): void
    {
        config([
            'secrets.backend' => 'vault',
            'secrets.vault.addr' => 'https://vault.test',
            'secrets.vault.token' => 'root-token',
            'secrets.vault.secret_path' => 'secret/data/xdr',
        ]);
        Http::fake(['*/v1/sys/health' => Http::response(['initialized' => true], 200)]);

        $result = app(SecretsValidationService::class)->validate();

        $this->assertFalse(collect($result['warnings'])->contains(fn ($w) => str_contains($w, 'Vault is unreachable')));
    }

    // =========================================================================
    // RotateInternalTokenCommand
    // =========================================================================

    public function test_rotate_dry_run_does_not_record_event(): void
    {
        $this->artisan('security:rotate-internal-token --dry-run')->assertSuccessful();
        $this->assertSame(0, SecurityHardeningEvent::where('event_type', SecurityHardeningEvent::EVENT_SECRET_ROTATION)->count());
    }

    public function test_rotate_env_backend_records_written_false(): void
    {
        config(['secrets.backend' => 'env']);
        $this->app->forgetInstance(SecretProvider::class);
        $this->app->singleton(SecretProvider::class, fn () => new SecretProviderManager());

        $this->artisan('security:rotate-internal-token')->assertSuccessful();

        $this->assertDatabaseHas('security_hardening_events', [
            'event_type' => SecurityHardeningEvent::EVENT_SECRET_ROTATION,
        ]);
        $event = SecurityHardeningEvent::where('event_type', SecurityHardeningEvent::EVENT_SECRET_ROTATION)->first();
        $this->assertSame('env', $event->details['backend']);
        $this->assertFalse($event->details['written']);
    }

    public function test_rotate_vault_backend_records_written_true(): void
    {
        config([
            'secrets.backend' => 'vault',
            'secrets.vault.addr' => 'https://vault.test',
            'secrets.vault.token' => 'root-token',
            'secrets.vault.secret_path' => 'secret/data/xdr',
        ]);
        $this->app->forgetInstance(SecretProvider::class);
        $this->app->singleton(SecretProvider::class, fn () => new SecretProviderManager());
        Http::fake(['*' => Http::response(['data' => ['data' => []]], 200)]);

        $this->artisan('security:rotate-internal-token')->assertSuccessful();

        $event = SecurityHardeningEvent::where('event_type', SecurityHardeningEvent::EVENT_SECRET_ROTATION)->first();
        $this->assertSame('vault', $event->details['backend']);
        $this->assertTrue($event->details['written']);
    }

    // =========================================================================
    // SecurityHardeningEvent — new event type registered
    // =========================================================================

    public function test_secret_rotation_is_a_valid_event_type(): void
    {
        $this->assertContains(SecurityHardeningEvent::EVENT_SECRET_ROTATION, SecurityHardeningEvent::EVENT_TYPES);
    }
}
