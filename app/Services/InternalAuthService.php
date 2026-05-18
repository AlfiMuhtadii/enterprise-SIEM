<?php

namespace App\Services;

use App\Models\SecurityHardeningEvent;

/**
 * Internal service-to-service authentication and event integrity.
 *
 * Tokens are time-bounded HMAC-SHA256 values. Event signatures are
 * deterministic HMAC over canonical envelope fields, enabling replay-safe
 * verification (same event → same signature, different trace_id → different
 * signature).
 *
 * Verification failures are always logged and NEVER destructive — callers
 * decide whether to drop, quarantine, or pass invalid events.
 */
class InternalAuthService
{
    private static function secret(): string
    {
        $s = env('XDR_INTERNAL_AUTH_SECRET', '');
        if ($s !== '') {
            return $s;
        }
        // Fall back to APP_KEY, stripping the base64: prefix if present.
        $key = config('app.key', '');
        if (str_starts_with($key, 'base64:')) {
            return base64_decode(substr($key, 7));
        }
        return $key;
    }

    // -----------------------------------------------------------------------
    // Service token signing / verification
    // -----------------------------------------------------------------------

    /**
     * Generate a time-bounded service token for the given service identity.
     * Format (base64): serviceId|timestamp|hmac
     */
    public static function signToken(string $serviceId, ?int $timestamp = null): string
    {
        $ts      = $timestamp ?? time();
        $message = $serviceId . '|' . $ts;
        $mac     = hash_hmac('sha256', $message, static::secret());
        return base64_encode($serviceId . '|' . $ts . '|' . $mac);
    }

    /**
     * Verify a service token. Returns false for missing, malformed, expired,
     * or MAC-invalid tokens. Window default: 300 s (5 min).
     */
    public static function verifyToken(string $token, int $windowSeconds = 300): bool
    {
        if ($token === '') {
            return false;
        }
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }
        $parts = explode('|', $decoded, 3);
        if (count($parts) !== 3) {
            return false;
        }
        [$serviceId, $ts, $mac] = $parts;
        if (!is_numeric($ts) || abs(time() - (int) $ts) > $windowSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $serviceId . '|' . $ts, static::secret());
        return hash_equals($expected, $mac);
    }

    // -----------------------------------------------------------------------
    // Event envelope integrity signing / verification
    // -----------------------------------------------------------------------

    /**
     * Sign an event envelope. The canonical message is:
     *   event_id|event_type|occurred_at|trace_id
     *
     * Deterministic: same envelope fields → same signature.
     * Replay-safe: a replayed event with a different trace_id produces a
     * different signature.
     */
    public static function signEvent(array $event): string
    {
        $message = implode('|', [
            $event['event_id']    ?? '',
            $event['event_type']  ?? '',
            $event['occurred_at'] ?? '',
            $event['trace_id']    ?? '',
        ]);
        return 'sha256=' . hash_hmac('sha256', $message, static::secret());
    }

    /**
     * Verify an event signature. Returns true on match, false otherwise.
     * On failure, logs a security_hardening_event — does NOT throw.
     */
    public static function verifyEvent(
        array $event,
        string $signature,
        bool $logFailure = true
    ): bool {
        $expected = static::signEvent($event);
        $valid    = hash_equals($expected, $signature);
        if (!$valid && $logFailure) {
            SecurityHardeningEvent::record(
                SecurityHardeningEvent::EVENT_SIGNATURE_FAILURE,
                $event['source_service'] ?? 'unknown',
                [
                    'event_id'   => $event['event_id']   ?? null,
                    'event_type' => $event['event_type']  ?? null,
                    'reason'     => 'hmac_mismatch',
                ],
                null,
                $event['trace_id'] ?? null
            );
        }
        return $valid;
    }
}
