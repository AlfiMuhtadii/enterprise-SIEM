<?php

return [
    'services' => [
        'ingestion-gateway' => [
            'responsibility' => 'Accept raw telemetry batches and publish telemetry.raw events.',
            'health_path' => '/health/services/ingestion-gateway',
            'runtime_url' => env('XDR_INGESTION_GATEWAY_URL', 'http://127.0.0.1:8091'),
            'produces' => ['telemetry.raw'],
            'consumes' => [],
        ],
        'telemetry-normalizer' => [
            'responsibility' => 'Normalize raw source logs into telemetry.normalized events.',
            'health_path' => '/health/services/telemetry-normalizer',
            'runtime_url' => env('XDR_NORMALIZER_WORKER_URL', 'http://127.0.0.1:8092'),
            'produces' => ['telemetry.normalized'],
            'consumes' => ['telemetry.raw'],
        ],
        'xdr-correlation' => [
            'responsibility' => 'Correlate normalized cross-domain telemetry into XDR alerts and incident updates.',
            'health_path' => '/health/services/xdr-correlation',
            'runtime_url' => env('XDR_CORRELATION_WORKER_URL', 'http://127.0.0.1:8093'),
            'produces' => ['xdr.alerts', 'incidents.updated'],
            'consumes' => ['telemetry.normalized'],
        ],
        'alert-writer' => [
            'responsibility' => 'Consume xdr.alerts batches and write idempotent alerts to PostgreSQL/OpenSearch.',
            'health_path' => '/health/services/alert-writer',
            'runtime_url' => env('XDR_ALERT_WRITER_URL', 'http://127.0.0.1:8095'),
            'produces' => ['alerts.created', 'xdr.alerts.dlq'],
            'consumes' => ['xdr.alerts'],
        ],
        'incident-builder' => [
            'responsibility' => 'Consume alert/incident events and build or update grouped SOC incidents.',
            'health_path' => '/health/services/incident-builder',
            'runtime_url' => env('XDR_INCIDENT_BUILDER_URL', 'http://127.0.0.1:8096'),
            'produces' => ['incidents.updated', 'incidents.builder.dlq'],
            'consumes' => ['alerts.created'],
        ],
        'ai-rag' => [
            'responsibility' => 'Generate guarded AI analysis and retrieve SOC knowledge context.',
            'health_path' => '/health/services/ai-rag',
            'runtime_url' => env('XDR_AI_RAG_SERVICE_URL', 'http://127.0.0.1:8094'),
            'produces' => ['ai.analysis.results', 'ai.analysis.completed'],
            'consumes' => ['ai.analysis.requests', 'incidents.updated'],
        ],
        'soc-control-plane' => [
            'responsibility' => 'Laravel UI/API for RBAC, workflow, incidents, cases, reports, and audit.',
            'health_path' => '/health/services/soc-control-plane',
            'produces' => ['ai.analysis.requests', 'incidents.updated'],
            'consumes' => ['xdr.alerts', 'ai.analysis.results'],
        ],
    ],
    'topics' => [
        'telemetry.raw' => ['retention_hours' => 24, 'dlq' => 'telemetry.raw.dlq'],
        'telemetry.normalized' => ['retention_hours' => 168, 'dlq' => 'telemetry.normalized.dlq'],
        'xdr.alerts' => ['retention_hours' => 720, 'dlq' => 'xdr.alerts.dlq'],
        'alerts.persisted' => ['retention_hours' => 720, 'dlq' => 'alerts.persisted.dlq'],
        'alerts.created' => ['retention_hours' => 720, 'dlq' => 'alerts.created.dlq'],
        'incidents.builder.dlq' => ['retention_hours' => 720, 'dlq' => null],
        'incidents.updated' => ['retention_hours' => 720, 'dlq' => 'incidents.updated.dlq'],
        'ai.analysis.requests' => ['retention_hours' => 168, 'dlq' => 'ai.analysis.requests.dlq'],
        'ai.analysis.results' => ['retention_hours' => 720, 'dlq' => 'ai.analysis.results.dlq'],
        'ai.analysis.completed' => ['retention_hours' => 720, 'dlq' => 'ai.analysis.completed.dlq'],
    ],
    'infrastructure' => [
        'redpanda' => [
            'rest_url' => env('XDR_REDPANDA_REST_URL', env('KAFKA_REST_URL', 'http://127.0.0.1:8082')),
            'timeout_seconds' => (int) env('XDR_REDPANDA_TIMEOUT_SECONDS', 5),
        ],
        'clickhouse' => [
            'http_url' => env('XDR_CLICKHOUSE_HTTP_URL', env('CLICKHOUSE_HTTP_URL', 'http://127.0.0.1:8123')),
            'database' => env('XDR_CLICKHOUSE_DB', env('CLICKHOUSE_DB', 'detector_analytics')),
            'user' => env('XDR_CLICKHOUSE_USER', env('CLICKHOUSE_USER', 'detector')),
            'password' => env('XDR_CLICKHOUSE_PASSWORD', env('CLICKHOUSE_PASSWORD', 'detector')),
            'timeout_seconds' => (int) env('XDR_CLICKHOUSE_TIMEOUT_SECONDS', 5),
            // ARCH-DB-SPLIT: off by default (postgres) -- zero behavior change
            // unless an operator opts in. "clickhouse" routes agent-submitted
            // telemetry (AgentIngestionController::telemetry()) to the
            // ClickHouse telemetry_events table instead of Postgres's. Same
            // flag name/values as ingest_telemetry_events.py's --target /
            // XDR_TELEMETRY_WRITE_TARGET so operators configure one thing.
            'telemetry_write_target' => env('XDR_TELEMETRY_WRITE_TARGET', 'postgres'),
            // DATA-TIERING: off by default -- zero behavior change unless an
            // operator opts in. When true, SecurityRetentionArchiveService
            // additionally writes archived rows into ClickHouse's
            // archived_records table (real, indexed, months-scale search)
            // alongside the existing local gzip JSONL archive (which stays
            // the durability safety net either way -- this is an additional
            // warm-tier index, not a replacement for it).
            'warm_tier_enabled' => (bool) env('XDR_DATA_TIERING_WARM_TIER_ENABLED', false),
        ],
        // DATA-TIERING (cold tier): off by default -- zero behavior change
        // unless an operator opts in. When true, SecurityRetentionArchiveService
        // additionally uploads the finished local gzip archive file to an
        // S3-compatible object store (config/filesystems.php's "s3" disk --
        // set AWS_ENDPOINT/AWS_BUCKET/AWS_USE_PATH_STYLE_ENDPOINT=true for a
        // local MinIO instance, see docker-compose.yml's "data-tiering"
        // profile) after it's fully written, alongside the local file (which
        // stays the durability guarantee either way) and the ClickHouse warm
        // tier if also enabled. This is a best-effort offload, not a
        // replacement -- an upload failure never blocks the archive/delete.
        'cold_tier' => [
            'enabled' => (bool) env('XDR_DATA_TIERING_COLD_TIER_ENABLED', false),
            'disk' => env('XDR_DATA_TIERING_COLD_TIER_DISK', 's3'),
            'prefix' => env('XDR_DATA_TIERING_COLD_TIER_PREFIX', 'archive'),
        ],
        'opensearch' => [
            'url' => env('XDR_OPENSEARCH_URL', 'http://127.0.0.1:9200'),
            'user' => env('XDR_OPENSEARCH_USER', ''),
            'password' => env('XDR_OPENSEARCH_PASSWORD', ''),
            'timeout_seconds' => (int) env('XDR_OPENSEARCH_TIMEOUT_SECONDS', 5),
            // ENT-SEC-OPENSEARCH-OPEN: OpenSearch's security plugin ships with a
            // bundled self-signed demo certificate for the HTTP layer — real,
            // externally-verifiable certs are tracked separately by
            // ENT-SEC-NO-TLS-INTERNAL. Until then, verification must stay off
            // when connecting over https to the demo cert, or every request
            // fails with an SSL trust error. Defaults to verifying (safe) so
            // this only turns off when explicitly configured.
            'verify_tls' => filter_var(env('XDR_OPENSEARCH_VERIFY_TLS', true), FILTER_VALIDATE_BOOL),
        ],
        'qdrant' => [
            'url' => env('XDR_QDRANT_URL', env('SOC_QDRANT_BASE_URL', 'http://127.0.0.1:6333')),
            'collection' => env('XDR_QDRANT_COLLECTION', env('SOC_QDRANT_COLLECTION', 'soc_knowledge')),
            'vector_size' => (int) env('XDR_QDRANT_VECTOR_SIZE', 384),
            'timeout_seconds' => (int) env('XDR_QDRANT_TIMEOUT_SECONDS', 5),
        ],
    ],
    'correlation' => [
        'engine' => env('XDR_CORRELATION_ENGINE', 'shadow'),
        'scope' => env('XDR_CORRELATION_SCOPE', 'identity-cloud'),
        'fallback_to_legacy' => (bool) env('XDR_CORRELATION_FALLBACK_TO_LEGACY', true),
        'health_timeout_seconds' => (float) env('XDR_CORRELATION_HEALTH_TIMEOUT_SECONDS', 5),
        'health_retries' => (int) env('XDR_CORRELATION_HEALTH_RETRIES', 2),
        'health_retry_sleep_ms' => (int) env('XDR_CORRELATION_HEALTH_RETRY_SLEEP_MS', 150),
        'fallback_failure_threshold' => (int) env('XDR_CORRELATION_FALLBACK_FAILURE_THRESHOLD', 3),
        'shadow_report_path' => env('XDR_CORRELATION_SHADOW_REPORT', 'reports/xdr_cutover_active_identity_cloud_saas.json'),
        'cutover_gate' => [
            'alert_type_match_min' => (float) env('XDR_CORRELATION_ALERT_TYPE_MATCH_MIN', 0.95),
            'alert_count_delta_max' => (float) env('XDR_CORRELATION_ALERT_COUNT_DELTA_MAX', 0.02),
            'evidence_match_min' => (float) env('XDR_CORRELATION_EVIDENCE_MATCH_MIN', 0.98),
            'p95_latency_ms_max' => (float) env('XDR_CORRELATION_P95_LATENCY_MS_MAX', 300),
            'duplicate_rate_max' => (float) env('XDR_CORRELATION_DUPLICATE_RATE_MAX', 0),
        ],
    ],
    'tenancy' => [
        // Strict mode: true = production posture.
        // false (default) = legacy / migration mode.
        // See docs/security/TENANT_STRICT_MODE.md before enabling in production.
        'strict_mode' => (bool) env('XDR_TENANT_STRICT_MODE', false),

        // Documentary: strict mode MUST be enabled before multi-tenant production go-live.
        'strict_mode_production_required' => true,
    ],

    // Shared secret for internal service-to-service HMAC tokens.
    // Set XDR_INTERNAL_AUTH_SECRET in .env (32+ random bytes recommended).
    // Falls back to APP_KEY when unset.
    'internal_auth_secret' => env('XDR_INTERNAL_AUTH_SECRET', ''),

    // ENV-CACHE-DRIFT-BATCH: read via config so a custom value survives config:cache.
    // Comma-separated list, or '*', or empty for none.
    'trusted_proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),

    // Force HTTPS in production (respected only when app.env === 'production').
    'force_https' => env('APP_FORCE_HTTPS', false),

    // Shadow alert consumer toggle (advisory; string 'true'/'false' for gate reporting).
    // ENV-CACHE-DRIFT-BATCH: read via config so it survives config:cache.
    'shadow_consumer_enabled' => env('XDR_SHADOW_CONSUMER_ENABLED', 'false'),

    // OBS-OTEL-TRACING (phase 5): OTLP/HTTP span export for the SOC control
    // plane. Empty (default) disables export entirely. Shared name with the
    // Go/Python pipeline services' XDR_OTEL_EXPORTER_ENDPOINT for a single
    // operator-facing knob across the whole platform.
    'otel_exporter_endpoint' => env('XDR_OTEL_EXPORTER_ENDPOINT', ''),

    'storage' => [
        'raw_telemetry' => ['driver' => 'clickhouse', 'retention_days' => 30],
        'incidents_workflow_rbac' => ['driver' => 'postgresql', 'retention_days' => 365],
        'searchable_telemetry' => ['driver' => 'opensearch', 'retention_days' => 90],
        'rag_vectors' => ['driver' => 'qdrant', 'retention_days' => 365],
    ],
];
