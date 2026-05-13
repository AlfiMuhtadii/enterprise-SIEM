# XDR Strangler Migration and Operational Validation

This phase keeps Laravel as the SOC control plane while gradually extracting high-throughput processing into specialized services.

Laravel remains responsible for:
- RBAC and authenticated SOC access.
- Incidents, workflow, analyst actions, reports, and dashboard.
- Operational visibility over extracted services.

Extracted service boundaries:
- `services/ingestion-gateway`: Go ingestion gateway for signed telemetry batches and `telemetry.raw` publishing.
- `services/normalizer-worker`: Go normalizer worker for schema validation and `telemetry.normalized` forwarding.
- `services/correlation-worker`: Go shadow/active-scoped correlation worker for identity/cloud/SaaS only.
- `services/alert-writer-service`: FastAPI alert writer for idempotent PostgreSQL/OpenSearch alert persistence.
- `services/incident-builder-service`: FastAPI incident builder for grouped incident updates from alert events.
- `services/ai-rag-service`: FastAPI AI/RAG service for defensive incident analysis, retrieval, embeddings, and metrics.
- Endpoint/DNS/proxy correlation remains shadow-prep only; no active mode or cutover is enabled.

Run realistic large-scale validation:

```powershell
python scripts\xdr_operational_validate.py --generate --events 52500 --workers 6 --duration-minutes 240 --noise 0.35 --output reports\xdr_operational_validation.json
python scripts\xdr_strangler_e2e_validate.py --events 50000 --baseline-events 100 --batch-size 1000 --output reports\xdr_strangler_e2e_validation.json
```

Run Laravel operational visibility:

```powershell
php artisan xdr:strangler-status
php artisan xdr:stream-maturity --consumers=6 --partitions=12 --replay-events=52500
php artisan xdr:large-scale-validate --normal=50000 --malicious=2500 --duration-minutes=240 --noise=0.35
php artisan xdr:storage-maturity
php artisan xdr:recovery-validate --scenario=node-restart
```

Run extracted services locally:

```powershell
cd services\ingestion-gateway
go run . -addr :8091

cd ..\normalizer-worker
go run . -file ..\..\storage\logs\xdr_realistic_large.jsonl

cd ..\ai-rag-service
python -m uvicorn main:app --host 0.0.0.0 --port 8094
```

Run extracted services with Docker Compose profile:

```powershell
docker compose --profile strangler up -d --build
```

If Redpanda is already running outside this Compose project, keep the default `host.docker.internal:8082` setting used by the strangler services.

Expected report fields:
- Throughput metrics.
- Latency percentile metrics.
- FP/FN estimates per telemetry domain.
- Replay stability metrics.
- Correlation degradation metrics.
- Storage pressure metrics.
- Consumer lag metrics.
- Distributed recovery metrics.
- Service extraction health.

Measured service endpoints:
- Go ingestion gateway: `http://127.0.0.1:8091/health`
- Go normalizer worker: `http://127.0.0.1:8092/health`
- FastAPI AI/RAG service: `http://127.0.0.1:8094/health`

Latest optimized validation:
- Report: `reports/xdr_strangler_e2e_optimized_validation.json`
- Events: `50000`
- Go ingestion throughput: `19138.41 eps`
- Go normalizer enqueue throughput: `44573.25 eps`
- Go normalizer end-to-end throughput: `9444.87 eps`
- Normalizer p95 batch latency: `36.86 ms`
- Stream lag: `0`
- Normalizer memory after run: about `33 MiB`

Optimization notes:
- Normalizer now uses per-request worker pools for CPU-bound normalization.
- Normalized telemetry no longer republishes the full raw payload; raw data remains in `telemetry.raw`.
- Producer path uses sharded buffered queues and parallel bulk publish.
- Gateway can throttle admission from normalizer `/metrics` using `XDR_NORMALIZER_METRICS_URL` and `XDR_MAX_NORMALIZER_QUEUE_DEPTH`.

Correlation shadow benchmark:

```powershell
docker compose --profile strangler up -d --no-deps correlation-worker
python scripts\xdr_correlation_shadow_benchmark.py --events 50000 --runs 5 --output reports\xdr_correlation_shadow_benchmark.json
```

Latest correlation benchmark:
- Report: `reports/xdr_correlation_shadow_benchmark.json`
- Mode: `shadow`
- Source of truth: Python/Laravel correlation
- Events: `50000`
- Python/Laravel correlation: `183294 eps`, p95 `274.105 ms`, alerts `1735`
- Go shadow correlation: `110636 eps`, p95 `474.291 ms`, alerts `1644`
- Evidence match rate: `1.0`
- Duplicate rate: `0.0` on both paths
- Alert type match rate: `0.6023`
- Go worker memory after benchmark: about `156 MiB`

Cutover rule:
- Do not cut over yet. Alert type parity is not stable enough.
- Start shadow hardening on identity/cloud only.
- Expand to endpoint/DNS/proxy only after type/evidence parity stabilizes.
- Incident creation must remain last.

Identity/cloud parity hardening:

```powershell
python scripts\xdr_generate_identity_cloud_golden.py
python scripts\xdr_correlation_shadow_benchmark.py --dataset samples\golden\xdr_identity_cloud_golden.jsonl --events 1000 --runs 5 --scope identity-cloud --output reports\xdr_correlation_identity_cloud_golden_diff.json
python scripts\xdr_correlation_shadow_benchmark.py --events 50000 --runs 5 --scope identity-cloud --output reports\xdr_correlation_identity_cloud_diff.json
```

Latest identity/cloud golden result:
- Report: `reports/xdr_correlation_identity_cloud_golden_diff.json`
- Status: `CUTOVER_READY`
- Alert type match rate: `1.0`
- Alert count delta: `0`
- Evidence match rate: `1.0`
- Severity mismatch: `0`
- Entity key mismatch: `0`
- Duplicate rate: `0.0`

Latest identity/cloud large replay result:
- Report: `reports/xdr_correlation_identity_cloud_diff.json`
- Status: `CUTOVER_READY`
- Alert type match rate: `1.0`
- Alert count delta: `0`
- Evidence match rate: `1.0`
- Severity mismatch: `0`
- Entity key mismatch: `0`
- Duplicate rate: `0.0`
- Scoped events: `19070`
- Go p95 latency: `209.621 ms`
- Worker p95 latency: `199 ms`
- Go throughput: `98838.25 eps`
- Python/Laravel p95 latency: `239.012 ms`
- Correlation worker memory after benchmark: about `59 MiB`
- Decision: identity/cloud satisfies the technical cutover gate, but keep shadow mode until an explicit staged cutover flag and rollback path are implemented.

Profiling:
- Go pprof is enabled on the correlation worker through the default debug endpoints.
- CPU profile: `http://127.0.0.1:8093/debug/pprof/profile?seconds=30`
- Heap profile: `http://127.0.0.1:8093/debug/pprof/heap`
- Goroutine profile: `http://127.0.0.1:8093/debug/pprof/goroutine?debug=1`

## Staged Identity/Cloud Correlation Cutover

The Go correlation worker is eligible only for `identity-cloud` cutover. Default remains shadow mode:

```env
XDR_CORRELATION_ENGINE=shadow
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
```

Supported engines:

- `legacy`: Python/Laravel remains active. Go may still be used for comparison if healthy.
- `shadow`: default. Python/Laravel is source of truth and Go is compared as shadow.
- `go`: Go is active only for `identity-cloud`; legacy remains shadow comparison.

Operational status and fallback check:

```powershell
php artisan xdr:correlation-cutover-status --json
```

If `XDR_CORRELATION_ENGINE=go` and the Go worker is unhealthy, the effective engine falls back to `legacy` when `XDR_CORRELATION_FALLBACK_TO_LEGACY=true`. The command writes an audit event for cutover state changes and automatic rollback.

Dual-write comparison report:

```powershell
python scripts\xdr_correlation_shadow_benchmark.py --engine go --scope identity-cloud --events 50000 --runs 5 --output reports\xdr_correlation_identity_cloud_cutover.json
```

Cutover monitoring fields include alert count delta, Go p95 latency, Go worker health, error rate, and stream-lag guidance. Endpoint, DNS, proxy, and firewall correlation must stay legacy until they pass separate golden tests, large replay parity, latency gate, and duplicate-rate checks.

## Staged Cutover Operational Validation Result

Run the full staged cutover validation for identity/cloud/SaaS only:

```powershell
python scripts\xdr_staged_cutover_validate.py --events 50000 --runs 5 --output-dir reports
```

Latest validated result:

- Status: `PASS`
- Scope: `identity-cloud` covering identity, cloud, and SaaS telemetry only
- Scoped replay events: `19070` from `50000` mixed/noisy XDR events
- Go active p95 latency: `194.069 ms`
- Go active throughput: `101636.7 eps`
- Alert count delta: `0`
- Alert type match: `1.0`
- Evidence match: `1.0`
- Severity mismatch: `0`
- Entity mismatch: `0`
- Duplicate rate: `0.0`
- Fallback activation count: `2` during rollback validation

Generated reports:

- `reports/xdr_staged_cutover_summary.json`
- `reports/xdr_staged_cutover_report.json`
- `reports/xdr_parity_drift_report.json`
- `reports/xdr_rollback_validation_report.json`
- `reports/xdr_replay_stability_report.json`
- `reports/xdr_correlation_latency_trend_report.json`

Operational guardrail remains unchanged: endpoint, DNS, proxy, and firewall correlation stay on legacy until they pass their own golden tests, large replay parity, latency gate, and rollback validation.

## Fallback Trigger Analysis And Soak Runtime Validation

Analyze whether fallback events were real runtime failures or intentional rollback validation:

```powershell
python scripts\xdr_fallback_trigger_analysis.py
```

Latest analysis:

- Total fallback events: `2`
- Intentional rollback-validation events: `2`
- Spontaneous fallback events: `0`
- Conclusion: fallback events came from forced rollback validation using an intentionally invalid worker URL, not from active Go runtime instability.

Run sustained Go correlation soak for identity/cloud/SaaS:

```powershell
python scripts\xdr_correlation_soak.py --duration-minutes 360 --batch-size 5000 --sleep-ms 100 --output reports\xdr_correlation_soak_6h.json
```

For overnight validation:

```powershell
python scripts\xdr_correlation_soak.py --duration-minutes 1440 --batch-size 5000 --sleep-ms 100 --output reports\xdr_correlation_soak_24h.json
```

Smoke result from a 1-minute run:

- Status: `PASS`
- Iterations: `117`
- Events processed: `585000`
- Fallback count: `0`
- p95 latency: `84.695 ms`
- Memory growth: `1.587 MB`
- Goroutine growth: `0`

Operational soak targets:

- fallback count = `0`
- request failures = `0`
- p95 latency < `300 ms`
- no sustained memory growth beyond threshold
- no goroutine growth/leak
- no stream lag drift when stream lag metrics are available

Generated reports:

- `reports/xdr_fallback_trigger_analysis.json`
- `reports/xdr_correlation_soak_smoke.json`
