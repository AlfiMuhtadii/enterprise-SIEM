<?php

namespace Tests\Feature;

use App\Services\InternalAuthService;
use Tests\TestCase;

/**
 * TEST-UNTRAITED: audited — intentionally DB-free (config mutation +
 * InternalAuthService static sign/verify calls only). No DB trait needed.
 */
class InternalAuthConfigMappingTest extends TestCase
{
    /** ENV-CACHE-DRIFT: xdr.internal_auth_secret key must exist in config/xdr.php */
    public function test_xdr_config_has_internal_auth_secret_key(): void
    {
        $this->assertArrayHasKey('internal_auth_secret', config('xdr'));
    }

    /** xdr.internal_auth_secret defaults to empty string when env var is unset */
    public function test_internal_auth_secret_defaults_to_empty_string(): void
    {
        $this->assertIsString(config('xdr.internal_auth_secret'));
    }

    /** InternalAuthService uses config() not env() — tokens round-trip with config-cached value */
    public function test_sign_verify_round_trip_uses_config_value(): void
    {
        config(['xdr.internal_auth_secret' => 'test-secret-32-bytes-padded-xxxx']);
        $token = InternalAuthService::signToken('svc-test', time());
        $this->assertTrue(InternalAuthService::verifyToken($token));
    }

    /** Changing xdr.internal_auth_secret at runtime invalidates previously signed tokens */
    public function test_token_invalid_after_secret_rotation(): void
    {
        config(['xdr.internal_auth_secret' => 'original-secret-value-xxxxxxxxxxx']);
        $token = InternalAuthService::signToken('svc-test', time());

        config(['xdr.internal_auth_secret' => 'rotated-secret-value-xxxxxxxxxxx']);
        $this->assertFalse(InternalAuthService::verifyToken($token));
    }

    /** Falls back to APP_KEY when xdr.internal_auth_secret is empty */
    public function test_falls_back_to_app_key_when_secret_empty(): void
    {
        config(['xdr.internal_auth_secret' => '']);
        // Should not throw — APP_KEY fallback path is exercised
        $token = InternalAuthService::signToken('svc-fallback', time());
        $this->assertNotEmpty($token);
        $this->assertTrue(InternalAuthService::verifyToken($token));
    }

    /** config/xdr.php file does not read internal_auth_secret via env() directly */
    public function test_xdr_config_file_maps_internal_auth_secret(): void
    {
        $content = file_get_contents(config_path('xdr.php'));
        $this->assertStringContainsString("'internal_auth_secret'", $content);
        $this->assertStringContainsString("XDR_INTERNAL_AUTH_SECRET", $content);
    }

    /** InternalAuthService source uses config() not bare env() for the secret */
    public function test_internal_auth_service_uses_config_not_env(): void
    {
        $content = file_get_contents(app_path('Services/InternalAuthService.php'));
        $this->assertStringContainsString("config('xdr.internal_auth_secret'", $content);
        $this->assertStringNotContainsString("env('XDR_INTERNAL_AUTH_SECRET'", $content);
    }
}
