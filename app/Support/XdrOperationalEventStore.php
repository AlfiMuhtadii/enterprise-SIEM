<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class XdrOperationalEventStore
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public static function append(
        string $eventType,
        array $payload,
        string $sourceService,
        ?string $sourceTopic = null,
        ?string $aggregateType = null,
        ?string $aggregateId = null,
        ?string $traceId = null,
        array $metadata = [],
        bool $replayable = true
    ): string {
        $eventId = 'evt-'.substr(hash('sha256', json_encode([
            'event_type' => $eventType,
            'payload' => $payload,
            'trace_id' => $traceId,
            'source_service' => $sourceService,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 40);

        DB::table('xdr_operational_events')->insertOrIgnore([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'schema_version' => self::SCHEMA_VERSION,
            'source_topic' => $sourceTopic,
            'source_service' => $sourceService,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'trace_id' => $traceId,
            'correlation_id' => $metadata['correlation_id'] ?? null,
            'occurred_at' => now(),
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'metadata' => json_encode($metadata + ['store_id' => (string) Str::uuid()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'replayable' => $replayable,
            'published_at' => $metadata['published_at'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $eventId;
    }
}
