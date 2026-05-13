<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SecurityLogger
{
    public static function log(string $event, array $data = []): void
    {
        if (! (bool) config('security_detector.enabled', true)) {
            return;
        }

        $payload = array_merge([
            'schema_version' => 1,
            'ts' => now()->toIso8601String(),
            'event' => $event,
        ], $data);

        $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        File::append((string) config('security_detector.log_path'), $line . PHP_EOL);
    }

    public static function requestId(): string
    {
        $request = request();
        $requestId = $request->attributes->get('request_id');
        if (is_string($requestId) && $requestId !== '') {
            return $requestId;
        }

        $requestId = (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);

        return $requestId;
    }

    public static function hashValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value));
        if ($normalized === '') {
            return null;
        }

        return hash_hmac('sha256', $normalized, self::hmacKey());
    }

    public static function latencyMs(): ?int
    {
        $start = request()->attributes->get('request_start');
        if (! is_float($start) && ! is_int($start)) {
            return null;
        }

        return (int) round((microtime(true) - $start) * 1000);
    }

    private static function hmacKey(): string
    {
        $key = (string) config('security_detector.hash_key', '');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $key !== '' ? $key : 'change-me';
    }
}
