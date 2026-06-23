<?php

namespace App\Services;

use App\Models\SecurityHardeningEvent;

class SecretsValidationService
{
    private const DEV_DEFAULTS = [
        'dev-secret-change-me',
        '',
    ];

    private const KNOWN_DEV_APP_KEYS = [
        'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    ];

    /**
     * Validate all platform secrets and return a structured report.
     *
     * Returns:
     *   ok       — true when no errors are present
     *   errors   — configuration problems that block safe operation
     *   warnings — sub-optimal configuration that should be addressed
     */
    public function validate(): array
    {
        $errors   = [];
        $warnings = [];

        // APP_KEY must be set (Laravel encrypts sessions with it)
        $appKey = config('app.key', '');
        if ($appKey === '') {
            $errors[] = 'APP_KEY is not set — Laravel session encryption is broken';
        } elseif (in_array($appKey, self::KNOWN_DEV_APP_KEYS, true)) {
            $warnings[] = 'APP_KEY appears to be an all-zero dev placeholder — regenerate with php artisan key:generate';
        }

        // SECURITY_DETECTOR_HASH_KEY used to sign security.jsonl event hashes
        $hashKey = config('security_detector.hash_key', '');
        if (in_array($hashKey, self::DEV_DEFAULTS, true)) {
            $warnings[] = 'SECURITY_DETECTOR_HASH_KEY is using dev default — set SECURITY_DETECTOR_HASH_KEY in production';
        }

        // XDR_INGEST_SECRET authenticates telemetry ingestion
        // Use getenv() so putenv()-based test overrides are visible (env() reads from
        // phpdotenv's ImmutableRepository which caches values at boot and ignores putenv).
        $ingestSecret = getenv('XDR_INGEST_SECRET') ?: '';
        if ($ingestSecret === 'dev-secret-change-me') {
            $warnings[] = 'XDR_INGEST_SECRET is using dev default — set XDR_INGEST_SECRET in production';
        } elseif ($ingestSecret === '') {
            $warnings[] = 'XDR_INGEST_SECRET is not set — ingestion-gateway HMAC auth is disabled';
        }

        // XDR_INTERNAL_AUTH_SECRET for service-to-service tokens
        $internalSecret = getenv('XDR_INTERNAL_AUTH_SECRET') ?: '';
        if ($internalSecret === '') {
            $warnings[] = 'XDR_INTERNAL_AUTH_SECRET is not set — internal service auth is falling back to APP_KEY';
        }

        // SOC_WEBHOOK_SECRET for external webhook delivery
        $webhookSecret = config('soc.webhook_secret', '');
        if ($webhookSecret === '' || $webhookSecret === null) {
            $warnings[] = 'SOC_WEBHOOK_SECRET is not set — webhook delivery authentication is disabled';
        }

        return [
            'ok'         => empty($errors),
            'errors'     => $errors,
            'warnings'   => $warnings,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Run validation and record a startup_validation hardening event.
     * Returns the validation result array.
     */
    public function validateAndRecord(): array
    {
        $result = $this->validate();
        $allIssues = array_merge(
            array_map(fn ($e) => ['level' => 'error',   'message' => $e], $result['errors']),
            array_map(fn ($w) => ['level' => 'warning', 'message' => $w], $result['warnings']),
        );
        if (!empty($allIssues)) {
            SecurityHardeningEvent::record(
                SecurityHardeningEvent::EVENT_STARTUP_VALIDATION,
                'laravel',
                ['issues' => $allIssues, 'ok' => $result['ok']],
            );
        }
        return $result;
    }
}
