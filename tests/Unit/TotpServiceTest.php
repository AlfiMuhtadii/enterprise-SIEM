<?php

namespace Tests\Unit;

use App\Services\TotpService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * IDENTITY-SSO-MFA (TOTP): verifies the dependency-free RFC 6238
 * implementation against the RFC's own published test vector, not just
 * self-consistency — a wrong HMAC/truncation would otherwise pass tests
 * that only check "generate then verify the same code".
 */
class TotpServiceTest extends TestCase
{
    private function callPrivate(object $obj, string $method, array $args)
    {
        $ref = new ReflectionMethod($obj, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($obj, $args);
    }

    public function test_base32_round_trip(): void
    {
        $service = new TotpService();
        $original = random_bytes(20);
        $encoded = $this->callPrivate($service, 'base32Encode', [$original]);
        $decoded = $this->callPrivate($service, 'base32Decode', [$encoded]);
        $this->assertSame($original, $decoded);
    }

    public function test_base32_encode_matches_known_vector(): void
    {
        $service = new TotpService();
        // RFC 4648 test vector: "12345678901234567890" -> base32.
        $encoded = $this->callPrivate($service, 'base32Encode', ['12345678901234567890']);
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $encoded);
    }

    public function test_generated_code_matches_rfc6238_test_vector(): void
    {
        // RFC 6238 Appendix B: secret (ASCII) "12345678901234567890", SHA1,
        // T=59s (period 30s) -> counter=1 -> published 8-digit code 94287082.
        // This service truncates to 6 digits, so the expected value is the
        // last 6 digits of that RFC-published number: 287082.
        $service = new TotpService();
        $base32Secret = $this->callPrivate($service, 'base32Encode', ['12345678901234567890']);
        $code = $this->callPrivate($service, 'generateCode', [$base32Secret, 1]);
        $this->assertSame('287082', $code);
    }

    public function test_verify_accepts_current_code(): void
    {
        $service = new TotpService();
        $secret = $service->generateSecret();
        $counter = intdiv(time(), 30);
        $code = $this->callPrivate($service, 'generateCode', [$secret, $counter]);

        $this->assertTrue($service->verify($secret, $code));
    }

    public function test_verify_accepts_code_within_drift_window(): void
    {
        $service = new TotpService();
        $secret = $service->generateSecret();
        $counter = intdiv(time(), 30) - 1; // one period in the past
        $code = $this->callPrivate($service, 'generateCode', [$secret, $counter]);

        $this->assertTrue($service->verify($secret, $code, window: 1));
    }

    public function test_verify_rejects_code_outside_drift_window(): void
    {
        $service = new TotpService();
        $secret = $service->generateSecret();
        $counter = intdiv(time(), 30) - 5; // well outside a 1-step window
        $code = $this->callPrivate($service, 'generateCode', [$secret, $counter]);

        $this->assertFalse($service->verify($secret, $code, window: 1));
    }

    public function test_verify_rejects_wrong_code(): void
    {
        $service = new TotpService();
        $secret = $service->generateSecret();

        $this->assertFalse($service->verify($secret, '000000'));
    }

    public function test_verify_rejects_malformed_input(): void
    {
        $service = new TotpService();
        $secret = $service->generateSecret();

        $this->assertFalse($service->verify($secret, 'abcdef'));
        $this->assertFalse($service->verify($secret, '12345')); // too short
        $this->assertFalse($service->verify($secret, '1234567')); // too long
    }

    public function test_provisioning_uri_contains_secret_and_issuer(): void
    {
        $service = new TotpService();
        $secret = $service->generateSecret();
        $uri = $service->provisioningUri('alice@example.com', 'Detector XDR', $secret);

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret='.$secret, $uri);
        $this->assertStringContainsString(rawurlencode('Detector XDR'), $uri);
    }

    public function test_generated_secrets_are_unique(): void
    {
        $service = new TotpService();
        $this->assertNotSame($service->generateSecret(), $service->generateSecret());
    }
}
