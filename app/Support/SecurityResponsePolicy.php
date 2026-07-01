<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

class SecurityResponsePolicy
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function activeEntries(string $key): array
    {
        $entries = self::loadEntries($key);
        $active = [];

        foreach ($entries as $target => $record) {
            if (!is_string($target) || !is_array($record)) {
                continue;
            }

            if (self::recordIsActive($record)) {
                $active[$target] = $record;
            }
        }

        return $active;
    }

    public static function pruneExpired(string $key): int
    {
        $entries = self::loadEntries($key);
        $kept = [];
        $removed = 0;

        foreach ($entries as $target => $record) {
            if (!is_string($target) || !is_array($record)) {
                $removed++;
                continue;
            }

            if (self::recordIsActive($record)) {
                $kept[$target] = $record;
            } else {
                $removed++;
            }
        }

        if ($removed > 0) {
            self::writeEntries($key, $kept);
        }

        return $removed;
    }

    public static function isIpFlagged(string $key, ?string $ip): bool
    {
        if ($ip === null || trim($ip) === '') {
            return false;
        }

        $entries = self::loadEntries($key);
        if (!array_key_exists($ip, $entries)) {
            return false;
        }

        $record = $entries[$ip];
        if (!is_array($record)) {
            return false;
        }

        return self::recordIsActive($record);
    }

    public static function isUserRevoked(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        $entries = self::loadEntries('revoke_user_ids');
        $key = (string) $userId;

        if (!array_key_exists($key, $entries)) {
            return false;
        }

        $record = $entries[$key];
        if (!is_array($record)) {
            return false;
        }

        return self::recordIsActive($record);
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadEntries(string $key): array
    {
        $baseDir = (string) config('security.response_policy_dir');
        if ($baseDir === '') {
            return [];
        }

        $path = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $key . '.json';
        if (!File::exists($path)) {
            return [];
        }

        try {
            $raw = File::get($path);
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return [];
            }
            if (isset($decoded['entries']) && is_array($decoded['entries'])) {
                /** @var array<string, mixed> $entries */
                $entries = $decoded['entries'];
                return $entries;
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $entries
     */
    private static function writeEntries(string $key, array $entries): void
    {
        $baseDir = (string) config('security.response_policy_dir');
        if ($baseDir === '') {
            return;
        }

        $path = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $key . '.json';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'version' => 1,
            'updated_at' => now()->toIso8601String(),
            'entries' => $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function recordIsActive(array $record): bool
    {
        $expiresAt = $record['expires_at'] ?? null;
        if (!is_string($expiresAt) || $expiresAt === '') {
            return true;
        }

        try {
            return CarbonImmutable::parse($expiresAt)->isFuture();
        } catch (\Throwable) {
            // RESP-POLICY-FAIL-OPEN: a malformed (non-empty) expiry is treated as
            // expired/inactive (fail-closed) rather than active-forever. A null or
            // empty expires_at still means "no expiry" (handled above).
            return false;
        }
    }
}
