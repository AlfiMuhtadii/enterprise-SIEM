# XDR Platform — Limitations and Corrected Claims

**Date:** 2026-06-22
**Status:** Authoritative. Read this before making any demo, thesis, or portfolio claim about the platform.

This document records the exact boundaries between what the platform implements, what it simulates, and what it does not do. It is the result of a read-only code audit conducted on 2026-06-22 and supersedes any looser language in earlier demo guides.

---

## 1. DemoScenarioSeeder — UI lifecycle data, not live detection proof

**What it does:**
`DemoScenarioSeeder::run()` calls `DemoPlatformPackagingService::launchDemoScenario()`, which:
- Reads a local JSON fixture from `fixtures/demo/scenarios/`
- Creates a `DemoScenarioRun` record in the `demo_scenario_runs` table
- Creates `DemoReadinessSnapshot` and `PlatformShowcaseExport` records

**What it does NOT do:**
- It does NOT POST events to the ingestion-gateway
- It does NOT produce messages on any Redpanda topic
- It does NOT trigger the Go correlation worker
- It does NOT create rows in `security_alerts` or `security_incidents`

**Correct claim:**
> "Running `php artisan db:seed --class=DemoScenarioSeeder` populates demo UI metadata for the Demo Platform module. It does not demonstrate live detection. The demo_scenario_runs table tracks scenario lifecycle; it is not an alert table."

**Evidence:** `database/seeders/DemoScenarioSeeder.php`, `app/Services/DemoPlatformPackagingService.php:23-56`

---

## 2. ingest_security_events.py — HTTP request log ingester, not XDR pipeline feeder

**What it does:**
`scripts/ingest_security_events.py` reads a JSONL file and writes rows to the `security_events` table:
```sql
INSERT INTO security_events (ts, event_type, event_id, ..., payload)
```

**What it does NOT do:**
- It does NOT post events to the ingestion-gateway Go service
- It does NOT produce messages on `telemetry.raw` or any Redpanda topic
- It does NOT trigger the normalizer-worker, correlation-worker, or alert-writer
- It does NOT create rows in `security_alerts`

**What `security_events` is:**
`security_events` is the HTTP request log table populated by `SecurityRequestLogger` middleware. It is the input table for the PHP ML detector (logistic regression on HTTP requests). It is a separate detection path from the Go XDR correlation engine.

**Correct claim:**
> "`ingest_security_events.py` feeds the HTTP-request-based ML classifier path only. To produce `security_alerts` rows via the XDR correlation engine, events must be POSTed to `ingestion-gateway POST /v1/ingest` with a valid HMAC-SHA256 signature and processed through the full Redpanda pipeline."

**Evidence:** `scripts/ingest_security_events.py:160`, `database/migrations/2026_03_04_000004_create_security_alerts_table.php`

---

## 3. demo_feed.py — Event tagger only; does not produce alerts by itself

**What it does:**
`scripts/demo_feed.py` reads a JSONL file, injects `demo_run_id` and sequential `trace_id` into each event, and writes:
- `storage/logs/<demo_run_id>_tagged.jsonl` — tagged events
- `storage/logs/<demo_run_id>-manifest.json` — time window for `--demo-run` filter

**What it does NOT do:**
- It does NOT send events anywhere
- It does NOT connect to the ingestion-gateway
- It does NOT produce Redpanda messages
- Calling it alone produces zero alerts

**The only path that produces `security_alerts` rows via the Go correlation engine:**
```
POST tagged events -> ingestion-gateway (POST /v1/ingest, HMAC-SHA256)
  -> telemetry.raw (Redpanda)
  -> normalizer-worker
  -> telemetry.normalized (Redpanda)
  -> correlation-worker (XDR_CORRELATION_SCOPE=identity-cloud, XDR_CORRELATION_EVENT_LOOP_ENABLED=true)
  -> xdr.alerts (Redpanda)
  -> alert-writer-service (XDR_EVENT_LOOP_ENABLED=true)
  -> security_alerts (PostgreSQL)
```
This requires `docker compose --profile strangler up` and both event loops enabled via env vars.

**Correct claim:**
> "`demo_feed.py` prepares events with a traceable `demo_run_id` for lineage purposes. It does not by itself prove end-to-end pipeline execution. Pipeline proof requires the tagged events to be sent through `ingestion-gateway/Redpanda` and processed by the Go correlation and alert-writer services."

**Evidence:** `scripts/demo_feed.py`, `services/correlation-worker/main.go:357-391`, `services/alert-writer-service/main.py:357-382`

---

## 4. demo_run_id filtering — field-level propagation now implemented

**Current state (post pipeline: propagate demo lineage into correlation alerts):**

The Go correlation-worker `makeAlert()` now propagates demo lineage fields from all contributing events into the `evidence` map:
- `evidence.demo_run_id` — scalar, set when all contributing events share one `demo_run_id`
- `evidence.demo_run_ids` — array of all contributing demo_run_ids
- `evidence.trace_ids` — all trace IDs from contributing events
- `evidence.source_event_ids` — all source event IDs
- `evidence.scenario_id` — scenario ID if present and unique
- `evidence.demo_lineage_present` — always `true` when demo fields are present

`php artisan security:alerts-report --demo-run=<id>` now reports:
- `FIELD_MATCH=PASS` — alerts matched by `evidence.demo_run_id` field (field-level lineage proven)
- `FIELD_MATCH=WARN` — alerts matched by manifest time-window only (demo_run_id not in evidence; event may not have triggered a rule, or alert was produced before this change)
- `FIELD_MATCH=FAIL` — no alerts found by either method

**Non-demo events** (without `demo_run_id`) are completely unaffected — no demo keys appear in their evidence.

**Historical note:** Alerts produced before this change only support manifest time-window fallback and will report `FIELD_MATCH=WARN`.

**Correct claim:**
> "For alerts produced after the lineage propagation change, `demo_run_id` is field-level proven in `evidence`. The `--demo-run` filter first tries field-level match and reports `FIELD_MATCH=PASS` when successful. Older alerts or events that did not trigger a rule will fall back to time-window and report `FIELD_MATCH=WARN`."

**Evidence:** `services/correlation-worker/main.go` (`Event` struct, `makeAlert()` lineage block), `app/Console/Commands/SecurityAlertsReportCommand.php` (`countFieldLevelMatches()`)

---

## 5. Shadow alert topics — producer-side only, no consumer

**What is implemented:**
The Go correlation worker publishes shadow alerts to:
- `xdr.alerts.shadow.endpoint` — endpoint behavioral detections (32 rules + behavioral analytics)
- `xdr.alerts.shadow.network` — DNS/proxy/firewall detections (9 rules)

**What is NOT implemented:**
No service in the codebase consumes `xdr.alerts.shadow.endpoint` or `xdr.alerts.shadow.network`. The alert-writer-service event loop subscribes only to `xdr.alerts` (the active topic). Shadow alerts are published and never read.

**Impact:** 121 shadow rules run correlation logic in the Go worker, but their output is unreachable from the SOC dashboard, the `security_alerts` table, the investigation workflow, and threat hunting. Shadow mode is architecturally complete from the producer side only.

**Correct claim:**
> "Shadow detection rules fire and produce alert payloads on shadow Kafka topics. These payloads are not currently persisted to any database, not visible in the SOC dashboard, and not available for investigation. They demonstrate rule logic but not end-to-end observability."

**Evidence:** `services/correlation-worker/main.go:241-267` (shadow publish), `services/alert-writer-service/main.py:358` (subscribes to `xdr.alerts` only)

---

## 6. ML classifier — HTTP request classifier on synthetic data, not XDR-wide model

**What it is:**
`scripts/train_ai_detector.py` implements a pure-Python multiclass logistic regression that classifies HTTP request events into: `normal`, `bruteforce`, `scan`, `injection`.

**Training data:**
`storage/app/security_dataset.csv` — synthetic data generated at project start (timestamps from 2026-03-04, IPs from RFC 5737 TEST-NET ranges, labels manually assigned). Not derived from real attack captures.

**Features:**
`path`, `status`, `latency_ms`, `has_sql_keywords`, `has_script_payload`, `method` — HTTP access log fields from the `security_events` table.

**What it is NOT:**
- It does NOT classify identity/cloud/SaaS telemetry
- It does NOT classify endpoint behavioral events
- It does NOT share features, labels, or a pipeline with the Go correlation engine
- It is NOT trained on real network traffic captures

**Relationship to XDR:**
The ML detector and the Go rule-based correlation engine are parallel, independent detection paths. The Go engine detects identity/cloud/SaaS threats via temporal correlation rules. The ML classifier detects HTTP-level attack patterns (brute-force, scanning, injection) against the Laravel application itself.

**Correct claim:**
> "The thesis title 'Multiclass Logistic Regression' refers to an HTTP request classifier applied to the Laravel application's own access logs. It is one detection component alongside the rule-based Go correlation engine. The two components operate on different data types, different tables, and through different pipelines. The ML model is trained on synthetic data labeled for academic demonstration purposes."

**Evidence:** `scripts/train_ai_detector.py:25-38`, `storage/app/security_dataset.csv:1-5`

---

## 7. SOAR — advisory/simulation/local workflow only

**What is implemented:**
- Playbook lifecycle (draft, active, deprecated) with dual-approval and simulation-first gates
- Blast-radius scoring per action type
- Append-only audit trail
- 10 allowed action types in Phase 1

**What the "execute" actions actually do:**
The 4 `simulate_*` actions (`simulate_endpoint_isolation`, `simulate_credential_revocation`, `simulate_firewall_block`, `simulate_token_revocation`) are `SIMULATION_ONLY_ACTIONS` — they produce simulation result records in the database. They do NOT call any external API, do NOT send commands to an endpoint agent, do NOT modify firewall rules.

The remaining 6 actions (`notify_analyst`, `create_incident`, `create_ticket`, `enrich_ioc`, `request_approval`, `create_watchlist_entry`) are local database operations. There are no outbound HTTP calls in `SoarOrchestrationService`. No Slack message is sent, no Jira ticket is created, no endpoint agent receives a command.

**Correct claim:**
> "SOAR orchestration implements the governance framework (approval gates, simulation-first, audit trail, blast-radius scoring) as a complete workflow engine. All actions are local advisory operations. No external systems are called. This is by design for the academic scope."

**Evidence:** `app/Models/SoarPlaybook.php:31-50`, `app/Services/SoarOrchestrationService.php:182-197`

---

## 8. Multi-tenancy — application-layer governance in a shared database

**What is implemented:**
`MultiTenantIsolationService` implements tenant isolation auditing, context propagation validation, namespace validation, and boundary violation reporting. These produce governance records in append-only tables (`tenant_isolation_audits`, `tenant_context_propagation_runs`, etc.) with `is_advisory=true`.

**What is NOT implemented:**
- No `ROW LEVEL SECURITY` in any PostgreSQL migration (confirmed by exhaustive grep)
- No `CREATE POLICY` in any migration
- No separate PostgreSQL schemas per tenant
- No `SET app.current_tenant` session variable enforcement
- All tables reside in the single `public` schema of a shared database

**Impact:** A query that omits a `WHERE tenant_id = ?` filter will return data across all tenants. There is no database-level enforcement preventing cross-tenant reads.

**Correct claim:**
> "Multi-tenancy is implemented as an application-layer governance and audit framework over a shared single-schema PostgreSQL database. It validates that tenant context is propagated correctly through the application but does not enforce isolation at the database level. This is appropriate for a single-organisation research platform."

**Evidence:** `app/Services/MultiTenantIsolationService.php:1-67`, database migrations (0 matches for `ROW LEVEL SECURITY`, `CREATE POLICY`, `schema_search_path`)

---

## Quick Reference — What Is Real vs. Simulated

| Component | Real / Implemented | Simulated / Advisory |
|---|---|---|
| Go correlation engine (identity/cloud/SaaS) | Real — 12 staged_active rules, 6h soak PASS | — |
| ingestion-gateway HMAC ingestion | Real — Go service, requires `--profile strangler` | — |
| normalizer-worker | Real — Go service, multi-format adapters | — |
| alert-writer Kafka consumer | Real — writes `security_alerts` when running | — |
| Redpanda event bus | Real — requires `docker compose up` | — |
| PHP ML classifier (HTTP brute/scan/injection) | Real — trains on CSV, classifies HTTP logs | Training data is synthetic |
| Endpoint agent (Python) | Real behavior collectors — posts to ingestion-gateway | Telemetry data in DB is seeded |
| Shadow endpoint/network correlation | Real rule logic in Go | No consumer; output unreachable |
| DemoScenarioSeeder | Creates demo UI records | Does NOT trigger detection pipeline |
| Live causal proof (demo_causal_verify.py) | Real pipeline path, field-level lineage | Synthetic data only; 2 cloud rules verified |
| SOAR execution | Real governance workflow | No external API calls |
| Multi-tenant isolation | Real audit/governance records | No DB-level RLS enforcement |
| External integrations (Okta/Jira/Slack) | Real pipeline code | Simulated delivery by default |
| DNS/proxy/firewall analytics | Real analysis logic + shadow correlation | Network data is seeded/synthetic |
| DLQ (telemetry.normalization_failed) | Real — normalizer isolates poison messages and writes to DLQ topic; alert-writer has DLQ topic | No consumer reads DLQ; records accumulate unprocessed |
| Internal auth hardening (all 4 services) | Real enforcement mode available (`XDR_ENFORCE_INTERNAL_AUTH=true`) | Default is permissive (demo-safe); validator WARNs per-service when unset (checks 16–19) |

---

---

## 9. Live pipeline readiness validator

`scripts/validate_live_xdr_pipeline.py` is a read-only tool that confirms all pipeline services are running and correctly configured before a demo. It performs **16 checks**.

### Check status semantics

| Status | Meaning | Blocks `LIVE_PIPELINE_READY`? |
|---|---|---|
| `PASS` | Check passed | No |
| `FAIL` (required=True) | Required check failed | **Yes** |
| `FAIL` (required=False) | Advisory check failed | No — counted in `warn_count` |
| `WARN` | Advisory security or observability note | **Never** — counted in `warn_count` only |
| `UNKNOWN` | Service unreachable or response unparseable | Yes when required=True |

`LIVE_PIPELINE_READY` is only `false` if one or more checks fail with `required=True` (or `UNKNOWN` with `required=True`). `WARN` and advisory `FAIL` checks are surfaced in the report but never block readiness.

### All 16 checks

| # | Component | Check | Required |
|---|---|---|---|
| 1 | ingestion-gateway | `/health` reachable | Yes |
| 2 | normalizer-worker | `/health` reachable | Yes |
| 3 | correlation-worker | `/health` reachable | Yes |
| 4 | alert-writer-service | `/health` reachable | Yes |
| 5 | incident-builder | `/health` reachable | Yes |
| 6 | Redpanda | REST API (`GET /topics`) reachable | Yes |
| 7 | Redpanda | Required topics exist (`telemetry.raw`, `telemetry.normalized`, `xdr.alerts`) | Yes |
| 8 | correlation-worker | `XDR_CORRELATION_EVENT_LOOP_ENABLED=true` | Yes |
| 9 | alert-writer-service | `XDR_EVENT_LOOP_ENABLED=true` | Yes |
| 10 | alert-writer-service | `XDR_ALERT_WRITER_AUTO_OFFSET_RESET` valid value | No (advisory) |
| 11 | incident-builder | `XDR_INCIDENT_BUILDER_AUTO_OFFSET_RESET` valid value | No (advisory) |
| 12 | normalizer-worker | Processing movement: `telemetry.raw` (advisory; NOT committed-offset lag) | No (advisory) |
| 13 | correlation-worker | Processing movement: `telemetry.normalized` (advisory; NOT committed-offset lag) | No (advisory) |
| 14 | Redpanda | Topic high watermarks — events have flowed through each topic | No (advisory) |
| 15 | Pandaproxy | Pandaproxy exposure — WARN if reachable without auth | No (WARN) |
| 16 | normalizer-worker | Internal auth posture (`XDR_ENFORCE_INTERNAL_AUTH`) | No (WARN) |
| 17 | alert-writer | Internal auth posture (`XDR_ALERT_WRITER_INTERNAL_TOKEN`) | No (WARN) |
| 18 | incident-builder | Internal auth posture (`XDR_INCIDENT_BUILDER_INTERNAL_TOKEN`) | No (WARN) |
| 19 | correlation-worker | Internal auth posture (`XDR_CORRELATION_INTERNAL_TOKEN`) | No (WARN) |

**Checks 8 and 9 are two independent flags for two separate services.** Both must be enabled for the full alert pipeline to function end-to-end.

| Flag | Service | What it controls |
|---|---|---|
| `XDR_CORRELATION_EVENT_LOOP_ENABLED` | correlation-worker (Go) | Consumes `telemetry.normalized`, produces `xdr.alerts` |
| `XDR_EVENT_LOOP_ENABLED` | alert-writer-service (Python) | Consumes `xdr.alerts`, writes `security_alerts` table |

The `docker-compose.yml` hardcodes `XDR_CORRELATION_EVENT_LOOP_ENABLED: "true"` for the containerised service (strangler profile). For local/out-of-Docker usage, both flags must be present in `.env`.

The script does NOT ingest data, publish to Redpanda, write to PostgreSQL, or start services.

```
python scripts/validate_live_xdr_pipeline.py
```

Exit codes: 0=all required checks PASS, 1=one or more required checks FAIL, 2=no required FAIL but some required checks UNKNOWN.

### Correct live demo path

```
# Step 1: verify pipeline is up and event loops are enabled
python scripts/validate_live_xdr_pipeline.py

# Step 2: tag-only dry run (no network calls)
python scripts/demo_feed.py --input fixtures/demo/attack_scenario.jsonl

# Step 3: pipeline dry-run (validate + tag, no POST)
python scripts/demo_feed.py --input fixtures/demo/attack_scenario.jsonl \
    --mode pipeline --dry-run \
    --ingest-url http://localhost:8091/v1/ingest

# Step 4: pipeline live (tag + POST to ingestion-gateway)
python scripts/demo_feed.py --input fixtures/demo/attack_scenario.jsonl \
    --mode pipeline \
    --ingest-url http://localhost:8091/v1/ingest

# Step 5: after 30-60s, check alerts (manifest time window)
php artisan security:alerts-report --minutes=5 --demo-run=<demo_run_id from output>

# Step 6: show the rule that fired
python scripts/show_rule.py --rule-id <rule_id from alerts report>
```

---

## 10. Causal live demo verifier

`scripts/demo_causal_verify.py` is a single reviewer-friendly command that orchestrates the three-stage causal proof end-to-end without manual steps.

**What it does:**
1. Runs `validate_live_xdr_pipeline.py` — stops immediately if `LIVE_PIPELINE_READY` is not `true`
2. Runs `demo_feed.py --mode pipeline` — POSTs tagged events to ingestion-gateway; extracts `demo_run_id` from output
3. Polls `php artisan security:alerts-report --demo-run=<id>` until `FIELD_MATCH=PASS`, `FIELD_MATCH=WARN`, or timeout (default 60s, 3s interval)
4. Prints a structured proof table with 8 steps
5. Writes `reports/demo-causal-<demo_run_id>.json` and `.md`

**Verdict definitions:**

| Verdict | Meaning |
|---|---|
| `LIVE_CAUSAL_PROOF=PASS` | `demo_run_id` found in `security_alerts.evidence` — field-level lineage proven end-to-end |
| `LIVE_CAUSAL_PROOF=WARN` | Events accepted, alerts found via manifest time-window fallback only — `demo_run_id` not in evidence |
| `LIVE_CAUSAL_PROOF=FAIL` | Pipeline not ready, ingestion failed, no alerts found, or timeout |

**Exit codes:** 0=PASS, 1=WARN, 2=FAIL.

**What it does NOT do:**
- Does NOT write directly to `security_alerts` or `security_events`
- Does NOT use `DemoScenarioSeeder` or create alerts manually
- Does NOT bypass ingestion-gateway
- Does NOT hide WARN/FAIL (no fake PASS)
- Does NOT require external network access

```
python scripts/demo_causal_verify.py [options]

Options:
  --input               Path to demo scenario JSONL (default: fixtures/demo/attack_scenario.jsonl)
  --ingest-url          Ingestion-gateway URL (default: http://localhost:8091/v1/ingest)
  --timeout-seconds     Alert polling timeout (default: 60)
  --poll-interval-seconds  Seconds between polls (default: 3.0)
  --no-report-write     Skip writing report files
  --verbose             Print full subprocess output at each step
```

**Evidence:** `scripts/demo_causal_verify.py`, `tests/demo_causal_verify/test_demo_causal_verify.py` (7 tests)

---

---

## 11. Live causal proof — what is verified and what is not

**Verified (2026-06-22, commit 2f05e44, run `demo-20260622-7cccce`):**

The platform has live causal proof for safe synthetic cloud telemetry. The verifier command:

```powershell
python scripts/demo_causal_verify.py --timeout-seconds 120
```

proved that 5 synthetic cloud events flow through the full strangler pipeline (ingestion-gateway -> Redpanda -> normalizer -> correlation -> alert-writer) and produce persisted `security_alerts` records with `evidence.demo_run_id` matching the injected `demo_run_id`. Two correlation rules fired in the verified run:

- `CLOUD_NEW_ACCESS_KEY` — detects cloud access key creation
- `CLOUD_SECURITY_SETTING_MODIFIED` — detects security setting changes (e.g. MFA disable)

The proof is field-level: `FIELD_MATCH=PASS` means `security_alerts.evidence->>'demo_run_id' = '<id>'` returned rows, not a time-window approximation.

The proof is repeatable: each run uses a unique `event_id` (= `trace_id`, format `{demo_run_id}-trace-{seq}`) so alert fingerprints never collide across runs.

**What this proof does NOT cover:**

- Production-grade XDR readiness — this is a research/academic platform running on a single local machine
- Real malware detection — the scenario uses synthetic RFC 5737 addresses and a fictional user
- Kernel EDR, host containment, or endpoint blocking — all endpoint response is advisory/simulation-only
- Full NDR — DNS/proxy/firewall analytics are shadow-only with no active blocking
- Identity attack chain or cross-domain lateral movement proof — the current 5-event scenario fires only cloud rules; a full identity chain would require additional events and rule thresholds
- Autonomous remediation — all SOAR actions are advisory or local simulation; no external API is called

**Correct claim:**

> "The platform verifies a real pipeline path from synthetic event ingestion to persisted alerts with field-level `demo_run_id` lineage. This proves the plumbing is functional for cloud correlation rules on synthetic data. It does not prove production-grade XDR readiness, real threat detection, or autonomous response capability."

**Evidence:** `scripts/demo_causal_verify.py`, `docs/guides/DEMO_CAUSAL_PROOF.md`, commit `2f05e44`

---

## 12. Validator processing movement checks — NOT true Kafka committed-offset lag

Checks 12 and 13 in `validate_live_xdr_pipeline.py` are named "processing movement" deliberately. They are **not** true Kafka consumer group committed-offset lag metrics.

**What the check measures:**

Two sources are combined per worker:
- Worker `/metrics` → `processed` counter: messages processed since last container restart (resets to 0 on every restart, regardless of the committed Kafka offset)
- Redpanda Admin API public_metrics → `max_offset` (high watermark for the input topic)

`delta = max_offset − processed`

**Why this is not lag:**

After a container restart, `processed = 0` while `max_offset` reflects all historical messages. `delta` equals `max_offset` — the entire topic history — even when the worker has committed its offset and is fully caught up. A large `delta` is not a problem in isolation.

True Kafka consumer group lag = `committed_offset − high_watermark`, read from the broker per consumer group. Obtaining this requires a consumer group query — a side effect. This validator is read-only and cannot perform one.

**What the check IS useful for:**

- `recreate_count >= 10`: consumer group being recreated repeatedly without advancing (survives restarts; indicates cycling on stale/out-of-range offset)
- `poll_error_count >= 10`: consumer stuck in a persistent Pandaproxy error loop
- `poison_skipped` / `dlq_written`: DLQ isolation activity — poison messages were encountered and isolated
- `delta > 500` after a long-running session: coarse indicator of slow processing (confirm with `rpk consumer-group describe`)

**Correct claim:**

> "Validator checks 12–13 measure `max_offset − processed_since_restart`. This is a coarse processing movement indicator, not true Kafka consumer group committed-offset lag. After any container restart, delta = max_offset (all history). The checks are advisory and do not block `LIVE_PIPELINE_READY`. For accurate lag, use `rpk consumer-group describe <group>`."

**Evidence:** `scripts/validate_live_xdr_pipeline.py` (`check_worker_processing_movement` function docstring)

---

## 13. DLQ and internal auth hardening — current scope

### Dead-Letter Queue (DLQ)

**What is implemented:**
- normalizer-worker (Go): when a message cannot be parsed or normalised after retries, it writes the raw bytes and error metadata to `telemetry.normalization_failed` topic, commits the offset, and continues polling. Poison messages do not block the consumer.
- alert-writer-service (Python): `xdr.alerts.dlq` topic reference in configuration.
- Validator check 12/13 surfaces `poison_skipped` and `dlq_written` counts from worker `/metrics`.

**What is NOT implemented:**
- No service consumes `telemetry.normalization_failed` or `xdr.alerts.dlq`. Records accumulate on the topics and are not reprocessed, alerted on, or expired automatically.
- There is no DLQ replay UI in the SOC dashboard.
- There is no dead-letter-specific alert or incident lifecycle.

**Correct claim:**
> "The normalizer isolates unparseable messages into a DLQ topic rather than blocking the pipeline. The DLQ topic exists and accumulates records. There is currently no consumer, replay mechanism, or SOC workflow for DLQ records — they require manual `rpk` inspection."

### Internal Auth Hardening

**What is implemented:**
- All four microservices expose `internal_auth_mode` in `/metrics` (`"permissive"` or `"enforced"`)
- Shared flag `XDR_ENFORCE_INTERNAL_AUTH=true` enables enforcement across all services
- Per-service tokens: `XDR_NORMALIZER_INTERNAL_TOKEN`, `XDR_ALERT_WRITER_INTERNAL_TOKEN`, `XDR_INCIDENT_BUILDER_INTERNAL_TOKEN`, `XDR_CORRELATION_INTERNAL_TOKEN`
- **normalizer-worker** (`/v1/normalize`): enforces `X-Internal-Service-Token`; startup `log.Fatalf` if token not set when enforced
- **alert-writer-service** (`/v1/write`, `/v1/process`): enforces token via FastAPI `Header`; startup `sys.exit(1)` if token not set when enforced
- **incident-builder-service** (`/v1/build`, `/v1/process`): same as alert-writer
- **correlation-worker** (`/v1/correlate`, `/v1/correlate-endpoint-shadow`): enforces token; startup `log.Fatalf` if not set when enforced
- `XDR_ENFORCE_INTERNAL_AUTH=false` (default): validator WARNs but all services remain usable for local demo without tokens
- Pandaproxy port bound to `127.0.0.1:8082` (loopback-only)
- Validator checks 15–19: check 15 WARNs on Pandaproxy exposure; checks 16–19 WARN per-service when permissive
- `WARN` status never blocks `LIVE_PIPELINE_READY`

**What is NOT implemented:**
- Mutual TLS between services
- Network policy or firewall rules enforced by docker-compose (Pandaproxy loopback binding is a port-level hint, not a hard firewall rule)

**Correct claim:**
> "Internal auth enforcement is available for all four microservice internal HTTP endpoints via `XDR_ENFORCE_INTERNAL_AUTH=true`. Default is permissive for local demo compatibility. Each service fails fast at startup if its token is missing when enforcement is enabled. The validator WARNs (advisory, non-blocking) for each service when running in permissive mode."

**Evidence:** `services/*/main.{go,py}` (`validateCorrelationSecrets`/`validateNormalizerSecrets`/`validate_startup_secrets`, `verifyInternalToken`/`verify_internal_token`, `metrics`), `docker-compose.yml` (per-service token env vars), `scripts/validate_live_xdr_pipeline.py` (checks 15–19)

---

*This document is maintained alongside `docs/KNOWN_LIMITATIONS.md`. For academic scope boundaries see `docs/thesis/THESIS_POSITIONING.md`. For operational posture see `docs/operations/OPERATIONAL_POSTURE.md`.*
