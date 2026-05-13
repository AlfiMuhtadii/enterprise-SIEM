<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SecurityIngestCommand extends Command
{
    protected $signature = 'security:ingest
                            {--file= : JSONL file path (default: storage/logs/security.jsonl)}
                            {--batch=500 : Batch size for DB writes}
                            {--from-start : Re-ingest from byte 0 (deduped by event_hash)}';

    protected $description = 'Ingest security.jsonl into security_events table';

    public function handle(): int
    {
        $filePath = (string) ($this->option('file') ?: storage_path('logs/security.jsonl'));
        $offsetPath = storage_path('app/security_ingest.offset');
        $batchSize = max(1, (int) $this->option('batch'));
        $fromStart = (bool) $this->option('from-start');

        if (!is_file($filePath)) {
            $this->error("File not found: {$filePath}");
            return self::FAILURE;
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            $this->error("Cannot open file: {$filePath}");
            return self::FAILURE;
        }

        $offset = 0;
        if (!$fromStart && is_file($offsetPath)) {
            $saved = trim((string) file_get_contents($offsetPath));
            if (is_numeric($saved)) {
                $offset = max(0, (int) $saved);
            }
        }

        $fileSize = filesize($filePath);
        if ($fileSize !== false && $offset > $fileSize) {
            $offset = 0;
        }

        if (fseek($handle, $offset) !== 0) {
            fclose($handle);
            $this->error('Failed to seek to saved offset.');
            return self::FAILURE;
        }

        $rows = [];
        $processed = 0;
        $invalid = 0;
        $inserted = 0;
        $lastPos = $offset;

        while (($line = fgets($handle)) !== false) {
            $lastPos = ftell($handle);
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $processed++;
            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                $invalid++;
                continue;
            }

            $rows[] = $this->mapRow($decoded, $trimmed);

            if (count($rows) >= $batchSize) {
                $inserted += $this->flushRows($rows);
                $rows = [];
            }
        }

        if (!empty($rows)) {
            $inserted += $this->flushRows($rows);
        }

        fclose($handle);
        file_put_contents($offsetPath, (string) $lastPos);

        $this->info("Processed: {$processed}");
        $this->info("Inserted: {$inserted}");
        $this->info("Invalid: {$invalid}");
        $this->info("Offset: {$lastPos}");

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function mapRow(array $event, string $rawLine): array
    {
        $payloadJson = json_encode($event, JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        $ts = $this->parseTs($event['ts'] ?? null);
        $eventType = (string) ($event['event'] ?? 'unknown');
        $requestId = $this->normalizeUuid($event['request_id'] ?? null);
        $path = $this->toNullableString($event['path'] ?? null, 1024);
        $ip = $this->toNullableString($event['ip'] ?? null, 45);

        return [
            'ts' => $ts,
            'event_type' => $eventType,
            'event_id' => $this->computeEventId($ts->toIso8601String(), $requestId, $eventType, $path, $ip),
            'event_hash' => hash('sha256', $rawLine),
            'request_id' => $requestId,
            'ip' => $ip,
            'user_agent_hash' => $this->toNullableString($event['user_agent_hash'] ?? null, 64),
            'user_id' => $this->toNullableInt($event['user_id'] ?? null),
            'email_hash' => $this->toNullableString($event['email_hash'] ?? null, 64),
            'method' => $this->toNullableString($event['method'] ?? null, 16),
            'path' => $path,
            'status' => $this->toNullableInt($event['status'] ?? null),
            'latency_ms' => $this->toNullableInt($event['latency_ms'] ?? null),
            'query_hash' => $this->toNullableString($event['query_hash'] ?? null, 64),
            'has_sql_keywords' => (bool) ($event['has_sql_keywords'] ?? false),
            'has_script_payload' => (bool) ($event['has_script_payload'] ?? false),
            'payload' => $payloadJson,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function flushRows(array $rows): int
    {
        return DB::table('security_events')->insertOrIgnore($rows);
    }

    private function parseTs(mixed $value): Carbon
    {
        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return now();
        }
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function toNullableString(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($trimmed, 0, $maxLength);
        }

        return substr($trimmed, 0, $maxLength);
    }

    private function normalizeUuid(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $trimmed)) {
            return null;
        }

        return strtolower($trimmed);
    }

    private function computeEventId(string $ts, ?string $requestId, string $eventType, ?string $path, ?string $ip): string
    {
        $parts = [
            $ts,
            $requestId ?? '',
            $eventType,
            $path ?? '',
            $ip ?? '',
        ];

        return hash_hmac('sha256', implode('|', $parts), (string) config('app.key', 'demo-ingest-key'));
    }
}
