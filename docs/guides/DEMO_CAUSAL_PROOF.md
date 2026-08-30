# Live Causal Proof — End-to-End XDR Pipeline

**Date:** 2026-06-22
**Status:** Verified. Use this document as the canonical evidence reference for demo, thesis, and portfolio review.

---

## What This Proves

Running `python scripts/demo_causal_verify.py` proves that synthetic cloud telemetry events flow through every service in the strangler XDR pipeline, trigger real correlation rules, and produce persisted alert records whose `evidence` JSON contains the exact `demo_run_id` that was injected at ingestion time.

This is a **field-level lineage proof**, not a dashboard screenshot or seeded data claim. The `demo_run_id` travels from `demo_feed.py` through Redpanda, the Go correlation worker, and the Python alert-writer, and lands in `security_alerts.evidence` in PostgreSQL — without any direct DB writes from the demo tooling.

This is **stronger than a preloaded dashboard claim** because:
- The verifier runs the pipeline live and waits for real output.
- `FIELD_MATCH=PASS` means the alert's `evidence` field contains the matching `demo_run_id`, not just a time-window approximation.
- The verifier exits non-zero on any failure — there is no fake PASS path.

**This is safe synthetic telemetry.** The scenario uses RFC 5737 IP addresses and a fictional corporate user. It does not represent real malware, exploit execution, offensive tooling, host compromise, or production XDR validation.

---

## Verifier Command

```powershell
python scripts/demo_causal_verify.py --timeout-seconds 120
```

Full options:

```
--input               Path to demo scenario JSONL (default: fixtures/demo/attack_scenario.jsonl)
--ingest-url          Ingestion-gateway URL     (default: http://localhost:8091/v1/ingest)
--timeout-seconds     Alert polling timeout     (default: 60)
--poll-interval-seconds  Seconds between polls  (default: 3.0)
--no-report-write     Skip writing report files
--verbose             Print full subprocess output at each step
--mtls-enabled        Require mTLS for gateway readiness and ingestion
--mtls-ca             PEM CA bundle used to verify ingestion-gateway
--mtls-client-cert    PEM client certificate presented to ingestion-gateway
--mtls-client-key     PEM private key for the client certificate
```

For a production ingestion gateway that requires client certificates, invoke the
feed directly with fail-closed mTLS enabled:

```powershell
python scripts/xdr_generate_internal_mtls_certs.py
python scripts/demo_causal_verify.py `
  --ingest-url https://localhost:8091/v1/ingest `
  --mtls-enabled `
  --mtls-ca storage/certs/internal-mtls/ca.crt `
  --mtls-client-cert storage/certs/internal-mtls/client.crt `
  --mtls-client-key storage/certs/internal-mtls/client.key
```

`--mtls-enabled` rejects plaintext URLs and incomplete certificate material
before the readiness request. The same hostname-verifying TLS context is used
for both `/health` and `/v1/ingest`. The default remains plaintext-compatible
for the local lab stack.

---

## Latest Verified Run

**Run ID:** `demo-20260622-7cccce`
**Verified:** 2026-06-22

```
======================================================================
  XDR CAUSAL LIVE DEMO VERIFIER
  input    : fixtures/demo/attack_scenario.jsonl
  ingest   : http://localhost:8091/v1/ingest
  timeout  : 120s  poll: 3.0s
======================================================================

[1/3] Running pipeline readiness validator...
  OK: pipeline ready.
[2/3] Sending demo events through pipeline...
  OK: demo_run_id=demo-20260622-7cccce  accepted=5
[3/3] Polling alerts report (timeout=120s, interval=3.0s)...

==================================================================================
  CAUSAL LIVE DEMO PROOF TABLE
==================================================================================
  Step                                     Evidence                               Status
----------------------------------------------------------------------------------
  Pipeline readiness                       validate_live_xdr_pipeline.py          PASS
  Events tagged with demo lineage          event_count=5, sent=5                  PASS
  Events accepted by ingestion-gateway     accepted=5                             PASS
  Manifest created                         demo_run_id=demo-20260622-7cccce       PASS
  Alerts report executed                   FIELD_MATCH=PASS                       PASS
  Field-level lineage match                2 alert(s) via demo_run_id             PASS
  Rule IDs found                           CLOUD_NEW_ACCESS_KEY, CLOUD_SECURITY_SETTING_MODIFIED PASS
  Evidence lineage present                 demo_lineage_present=true              PASS
----------------------------------------------------------------------------------
  FINAL VERDICT                            LIVE_CAUSAL_PROOF=PASS                 PASS
==================================================================================

  LIVE_CAUSAL_PROOF=PASS
```

**Rules fired:**
- `CLOUD_NEW_ACCESS_KEY` — cloud event with `CreateAccessKey` action; risk score 0.73
- `CLOUD_SECURITY_SETTING_MODIFIED` — cloud event with `DisableMFA` action; risk score 0.73

**Lineage confirmed:**
- `demo_lineage_present=true` in `security_alerts.evidence`
- `evidence.demo_run_id = "demo-20260622-7cccce"`
- `evidence.trace_ids` — per-event trace IDs in format `{demo_run_id}-trace-{seq}`

**Generated report files:**
- `reports/demo-causal-demo-20260622-7cccce.json`
- `reports/demo-causal-demo-20260622-7cccce.md`

---

## Causal Path

Every hop below is a real service boundary. No shortcuts, no direct DB writes from the demo tooling.

```
fixtures/demo/attack_scenario.jsonl
  |
  | [demo_feed.py --mode pipeline]
  |   - injects demo_run_id, trace_id (= {demo_run_id}-trace-{seq}), source_event_id
  |   - replaces event_id with trace_id (unique per run, prevents fingerprint collisions)
  |   - POSTs each event to ingestion-gateway with HMAC-SHA256 X-XDR-Signature
  v
ingestion-gateway  (Go, port 8091)
  - validates HMAC-SHA256 signature
  - rate-limits at 100 RPS
  - publishes to Redpanda
  v
telemetry.raw  (Redpanda topic)
  v
normalizer-worker  (Go, port 8092)
  - reads raw event
  - maps to normalised schema
  - preserves demo lineage fields (demo_run_id, trace_id, source_event_id)
  - publishes to telemetry.normalized
  v
telemetry.normalized  (Redpanda topic)
  v
correlation-worker  (Go, port 8093, XDR_CORRELATION_SCOPE=identity-cloud)
  - reads normalised event
  - runs correlateIdentityCloud() — groups by user, evaluates cloud/identity rules
  - makeAlert() aggregates contributing event_ids and demo lineage into evidence
  - publishes alert batch to xdr.alerts
  v
xdr.alerts  (Redpanda topic)
  v
alert-writer-service  (Python/FastAPI, port 8095)
  - reads alert batch from xdr.alerts
  - deduplicates by alert_fingerprint
  - writes new alerts to security_alerts (PostgreSQL) + OpenSearch
  - publishes alerts.created to Redpanda
  v
security_alerts  (PostgreSQL table)
  - evidence JSON column contains: demo_run_id, demo_run_ids, trace_ids, demo_lineage_present=true
  v
php artisan security:alerts-report --demo-run=<demo_run_id>
  - queries security_alerts WHERE evidence->>'demo_run_id' = '<demo_run_id>'
  - reports FIELD_MATCH=PASS when at least one alert found by field-level match
```

---

## What FIELD_MATCH=PASS Means

`FIELD_MATCH=PASS` means the SQL query:

```sql
SELECT * FROM security_alerts
WHERE evidence->>'demo_run_id' = 'demo-20260622-7cccce'
```

returned at least one row. The `demo_run_id` was injected by `demo_feed.py` at ingestion time and propagated through every pipeline hop into the persisted alert record. This is not inferred from timestamps or seeded data.

Three possible outcomes of `security:alerts-report --demo-run`:

| Outcome | Meaning |
|---|---|
| `FIELD_MATCH=PASS` | `demo_run_id` found in `evidence` — field-level lineage proven end-to-end |
| `FIELD_MATCH=WARN` | Alerts found by manifest time-window only — `demo_run_id` not in evidence (older alert or event did not trigger a rule) |
| `FIELD_MATCH=FAIL` | No alerts found by either method — pipeline not processing events |

---

## Scenario Description

The scenario (`fixtures/demo/attack_scenario.jsonl`) contains 5 synthetic events representing a cloud credential theft chain:

| Event | Type | What it represents |
|---|---|---|
| `mfa_failure` x3 | identity | Repeated MFA failures (risk scores 0.6, 0.6, 0.7) |
| `new_access_key_created` | cloud | AWS `CreateAccessKey` — permanent credential creation |
| `security_setting_modified` | cloud | AWS `DisableMFA` — MFA disabled on account |

All IPs are from RFC 5737 TEST-NET (`198.51.100.0/24`). User is `alice@corp.example`. No real credentials, no real cloud account, no real hosts.

---

## Prerequisites

The full strangler pipeline must be running:

```powershell
# Infrastructure (Redpanda, PostgreSQL, OpenSearch, ...)
docker compose up -d

# Go + Python pipeline services
docker compose --profile strangler up -d

# Verify readiness before running the demo
python scripts/validate_live_xdr_pipeline.py
```

Required env vars (in `.env`):
```
XDR_INGEST_SECRET=<your-secret>
XDR_CORRELATION_EVENT_LOOP_ENABLED=true
XDR_EVENT_LOOP_ENABLED=true
```

---

## What This Does Not Prove

- Production-grade XDR readiness
- Real malware detection or exploit detection
- Kernel EDR, full NDR, or host-level containment
- Identity attack chain or cross-domain lateral movement (requires more events and rules beyond this 5-event scenario)
- Autonomous remediation (all response actions are advisory/simulation-only)
- Detection accuracy on real-world telemetry (model and rules are trained/tuned on synthetic data)

---

## Repeating the Proof

The verifier is idempotent. Run it multiple times — each run generates a new `demo_run_id`, new unique `event_id`s (via `trace_id` assignment), and therefore new non-duplicate alerts in the database.

```powershell
# Run 1
python scripts/demo_causal_verify.py --timeout-seconds 120

# Run 2 — produces a different demo_run_id, different alert fingerprints, same PASS result
python scripts/demo_causal_verify.py --timeout-seconds 120
```

If the pipeline is unavailable, the verifier exits with `LIVE_CAUSAL_PROOF=FAIL` — it never produces a fake PASS.

---

*See also:*
- *[Live Pipeline Recovery Runbook](LIVE_PIPELINE_RECOVERY_RUNBOOK.md) — troubleshooting FAIL results*
- *[Limitations and Corrected Claims](LIMITATIONS_AND_CLAIMS.md) — what this proof does and does not cover*
- *`scripts/validate_live_xdr_pipeline.py` — pre-demo readiness checker*
- *`scripts/demo_causal_verify.py` — verifier source*
