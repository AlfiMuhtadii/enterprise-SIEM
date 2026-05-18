<?php

namespace App\Support;

/**
 * Presentation-layer redactor for trace investigation views and APIs.
 *
 * Rules:
 *   - JSON payload fields (evidence, raw_event, payload, metadata, config, results) are
 *     decoded and recursively redacted: sensitive keys → [REDACTED], email values → [EMAIL].
 *   - Top-level investigative identifier fields (actor_key, ip, alert_type, …) are passed
 *     through unchanged so analysts can do their job.
 *   - Top-level fields whose KEY name matches a secret pattern are redacted regardless.
 *   - Raw data in the database is NEVER mutated — redaction is applied only to the copy
 *     returned for rendering or API serialisation.
 */
final class TraceRedactor
{
    public const REDACTED = '[REDACTED]';
    public const EMAIL_PLACEHOLDER = '[EMAIL]';

    /** Fields whose DB values are JSON strings and require deep, email-aware redaction. */
    public const JSON_PAYLOAD_FIELDS = [
        'payload', 'evidence', 'raw_event', 'metadata', 'config', 'results',
    ];

    /**
     * Substrings that make a key name sensitive (case-insensitive match).
     * Chosen to cover secrets/tokens/auth while not catching investigative
     * identifiers like actor_key, alert_type, ip, etc.
     */
    private const SENSITIVE_PATTERNS = [
        'password', 'passwd', 'pwd',
        'secret',
        'token',
        'api_key', 'apikey',
        'authorization',
        'bearer',
        'cookie',
        'private_key', 'signing_key', 'encryption_key', 'hmac_key',
        'session_id',
        'ssn', 'credit_card', 'card_number', 'cvv',
        'email',
    ];

    private const EMAIL_REGEX = '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/';

    // ------------------------------------------------------------------
    // Public surface
    // ------------------------------------------------------------------

    /**
     * Redact a single DB row (stdClass).
     * JSON payload fields are decoded and recursively redacted (emails included).
     * Other string fields are redacted only when their key name is sensitive.
     * Returns a new object; the input is not mutated.
     */
    public static function row(\stdClass $row): \stdClass
    {
        $out = new \stdClass();
        foreach (get_object_vars($row) as $key => $value) {
            if (in_array($key, self::JSON_PAYLOAD_FIELDS, true) && is_string($value)) {
                $out->$key = self::decodeAndRedact($value);
            } elseif (self::isSensitiveKey($key)) {
                $out->$key = self::REDACTED;
            } else {
                $out->$key = $value;
            }
        }
        return $out;
    }

    /**
     * Redact every stdClass item in a collection.
     */
    public static function collection(\Illuminate\Support\Collection $rows): \Illuminate\Support\Collection
    {
        return $rows->map(fn ($r) => $r instanceof \stdClass ? self::row($r) : $r);
    }

    /**
     * Decode a JSON string and return the redacted PHP structure.
     * Email addresses in string values are also replaced.
     * Returns null when the input is null/empty or cannot be decoded.
     */
    public static function decodeAndRedact(?string $json): mixed
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || $decoded === null) {
            return null;
        }
        return self::deep($decoded, redactEmails: true);
    }

    /**
     * Recursively redact any value.
     * When $redactEmails is true, string values matching the email regex become [EMAIL].
     * Sensitive keys always become [REDACTED] regardless of their value.
     * The input is never mutated; new arrays/objects are produced at each level.
     */
    public static function deep(mixed $value, bool $redactEmails = false): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::isSensitiveKey((string) $k)
                    ? self::REDACTED
                    : self::deep($v, $redactEmails);
            }
            return $out;
        }
        if ($value instanceof \stdClass) {
            $out = new \stdClass();
            foreach (get_object_vars($value) as $k => $v) {
                $out->$k = self::isSensitiveKey($k) ? self::REDACTED : self::deep($v, $redactEmails);
            }
            return $out;
        }
        if ($redactEmails && is_string($value)) {
            return preg_replace(self::EMAIL_REGEX, self::EMAIL_PLACEHOLDER, $value) ?? $value;
        }
        return $value;
    }

    /**
     * Return true when the key name implies the value is sensitive.
     * Uses a substring match so that e.g. "user_password", "api_token", "session_cookie"
     * all match.  Investigative identifiers (actor_key, alert_type, ip, …) do not match.
     */
    public static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
