# Rule Promotion Checklist

Step-by-step gates for advancing a rule through the promotion stages. All gates must pass before promotion. No gate may be bypassed.

```
draft → shadow → staged_active → deprecated
```

---

## Gate Set 1: draft → shadow

All must be true before a rule may enter shadow mode.

- [ ] `rule_id` registered in `docs/detection/rules/registry.v1.json` as `status: draft`
- [ ] `rule_id` is globally unique (validator confirms no collision)
- [ ] `title` and `description` written
- [ ] `severity` set and justified (see governance policy)
- [ ] `confidence` set and justified (must be ≥ 0.65 to advance beyond draft)
- [ ] `mitre_attack` populated (required for endpoint and threat-intel rules; recommended for all)
- [ ] `false_positive_notes` written with at least one scenario
- [ ] `suppression_key` defined and reviewed by owner
- [ ] `owner` assigned
- [ ] Detection logic implemented in the appropriate service
- [ ] `output_topic` confirmed: shadow rules must use `xdr.alerts.shadow.*`, NOT `xdr.alerts`
- [ ] `shadow_only: true` set for endpoint and threat-intel rules
- [ ] `replay_fixture` created and path registered in registry
- [ ] `expected_alert_count` declared and matches fixture replay output
- [ ] Registry validation passes: `python scripts/xdr_rule_registry_validate.py`
- [ ] Status updated to `shadow` in registry, `updated_at` updated

---

## Gate Set 2: shadow → staged_active

All must be true before a rule may enter staged active mode.

The advisory pipeline preserves `shadow_evidence_origin: rehearsal` for
events with demo/scenario markers or explicit simulation flags. Rehearsal
findings remain visible for audit, but are separate from unverified findings
and excluded from nomination, the soak harness snapshot, preflight, and rule summaries.
Missing or self-asserted origin is not proof of representative telemetry.
The preflight's `preflight_ready` field means diagnostic readiness only:
`promotion_evidence_verified` remains false. Maintain operator-reviewed live
source records and the measured domain-specific 6h soak separately.

For host or sensor capture, a pilot gateway can use an operator-controlled,
read-only JSON file at `XDR_CAPTURE_SOURCES_FILE`. It is off by default and
requires server mutual TLS, `XDR_INGEST_SIGV2_REQUIRED=true`, and a configured
ingest secret of at least 32 bytes. Each entry binds one verified client leaf
certificate (SHA-256 of DER) to an exact source identifier, tenant, and list
of raw telemetry types:

```json
[
  {
    "source_id": "pilot-endpoint-01",
    "client_cert_sha256": "<64 hexadecimal characters>",
    "tenant_id": "pilot-tenant",
    "telemetry_types": ["endpoint"]
  }
]
```

Keep the client keys outside the registry. A registered client must provide
an explicit matching `tenant_id`, an allowed `telemetry_type`, and a stable
`event_id` on every event, or the entire request is rejected. The gateway
removes request-supplied `capture_admission` and adds its own stamp only for
approved certificate holders. Unregistered clients do not receive a stamp
and existing ingestion authentication still applies. When enabling this
on an existing listener, review client certificate compatibility first. For a
container deployment, supply the file as a read-only mount and set the variable
inside that container using the pilot deployment override.

Reconcile the admission stamp in `telemetry.raw` with independent capture
records using the event ID, tenant, type, and time window. The stamp does
not validate the underlying observation or authorize promotion. Records from
known rehearsal sources remain excluded even when transported using mTLS.

Use the local read-only reconciler against Kafka data within a reviewed
offset range. Retain the exact event JSONL at the collector **before** it
is published; a Kafka export reused as source data is not independent.

```powershell
python scripts/xdr_reconcile_capture.py `
    --capture approved-source-capture.jsonl --registry pilot-sources.json `
    --source-id pilot-endpoint-01 --domain endpoint --telemetry-type endpoint `
    --window-start 2026-01-01T00:00:00Z --window-end 2026-01-01T06:00:00Z `
    --range 0:1200:1500 --normalized-range 0:490:760
```

Replace the example dates and offsets with the recorded UTC observation
window and reviewed topic partitions. An offset range starts inclusively
and ends exclusively. Repeated `--range` flags select distinct raw partitions;
`--normalized-range` selects independently recorded offsets in
`telemetry.normalized`. Do not reuse raw offsets on the normalized topic.
The tool uses the [Redpanda partition read API](https://docs.redpanda.com/api/doc/http-proxy/operation/operation-get_topics_records)
on the local loopback listener, without a consumer group or offset commits.
Missing, changed, duplicate, or unlisted eligible records fail reconciliation.
Observed and admitted timestamps must be in the window and within five minutes
of each other. No event payloads or IDs are included in the report.

For correlation-input lineage, require `normalized_lineage.status=MATCHED`.
Without `--normalized-range`, the check stops at raw admission. With that
option, each admitted source record must have a unique normalized output
with the same tenant, telemetry type, raw and normalized event IDs, trace
ID, and unchanged source timestamp. An unrelated normalized record is not evidence
against the capture because the normalized stream can mix sources. A missing,
rehearsed, duplicate, or altered matching output fails the combined check.
This does not compare transformed payloads, prove that the correlation worker
consumed the output, or certify the completeness of the supplied offsets.
`--broker-port` may select an isolated local HTTP proxy; its host remains
loopback.

A `MATCHED` result is scoped to the supplied source files and requested
offsets. Restrict Kafka writes to authorized infrastructure and retain the
independent capture and registry hashes with the report. It does not certify
the collector or the dataset as representative and does not record a soak
PASS, approve promotion, or inspect the active alert path.

For repeated DNS TXT/NXDOMAIN, proxy egress, and denied firewall aggregates,
use the finding's source event IDs to tie it back to the capture. Repeated
deliveries of the same event ID do not add to the trigger count. A finding
has a single trace only when every contributor has that trace; otherwise
inspect `source_trace_ids` and the individual event IDs. Known incomplete
lineage remains reviewable but cannot be nominated for promotion. Lists are
bounded to 256 entries each; a truncated list is incomplete even when the
aggregate counted more contributors. Complete
lineage alone does not verify representative telemetry or a live soak.

UEBA current endpoint observations resolve tenant ownership through the enrolled
agent rather than matching a hostname across tenants. Tenant-scoped user
detection excludes identity and SaaS integrations without tenant attribution;
an API result of `unattributed_sources_excluded` is not evidence of a clean
observation window. Detection requires tenant context for non-admins. The
admin-only `legacy_unscoped` user scores and historical scores computed before
this isolation are not proof of tenant-scoped activity. This control does not
establish a UEBA Kafka producer or a live soak.

### Contract and Schema
- [ ] Event contract validation passes: `python scripts/xdr_contract_validate.py`
- [ ] Docker compose config valid: `docker compose config --quiet`
- [ ] Rule output schema matches event contract (alert fields documented)

### Replay Validation
- [ ] Replay fixture produces exactly `expected_alert_count` alerts (no regression)
- [ ] Replay is idempotent: replaying the same fixture twice produces the same alert set
- [ ] No duplicate alert IDs from the same fixture input

### False-Positive Review
- [ ] False-positive scenarios from `false_positive_notes` tested against real or representative telemetry
- [ ] False-positive rate assessed and acceptable for `staged_active`
- [ ] Owner sign-off on false-positive rate

### Shadow Soak
- [ ] Rule has run in shadow mode for a minimum observation period (recommended: 48 hours on representative traffic)
- [ ] Shadow alert volume reviewed (not generating implausible alert volumes)
- [ ] No goroutine leak or memory growth observed during shadow soak
- [ ] `shadow_alerts_published` metric is non-zero (confirms rule is firing)

### Documentation
- [ ] `validation_evidence` field populated in registry (reference to soak report or validation artifact)
- [ ] `suppression_guidance` documented in registry
- [ ] Rollback plan documented: how to revert this rule to shadow or draft if issues arise

### Owner and Approval
- [ ] Owner has reviewed the promotion request
- [ ] At least one peer review of the detection logic
- [ ] Status transition documented in `changelog` in registry

### Final Registry Validation
- [ ] `python scripts/xdr_rule_registry_validate.py` — all checks PASS
- [ ] `output_topic` confirmed as `xdr.alerts` for staged_active
- [ ] `shadow_only: false` set
- [ ] Status updated to `staged_active` in registry, `updated_at` updated

---

## Gate Set 3: staged_active → deprecated

All must be true before a rule is deprecated.

- [ ] Owner decision documented (reason for deprecation)
- [ ] Replacement rule (if any) registered and promoted to at least `shadow`
- [ ] All existing suppressions referencing this rule_id cleared or transferred to replacement
- [ ] Downstream consumers notified (SOC team, any automated processes reading this rule's alerts)
- [ ] Rollback plan: if the replacement rule is insufficient, how to restore this rule
- [ ] Status updated to `deprecated` in registry, `updated_at` updated
- [ ] Detection logic disabled or guarded (rule must not fire after deprecation)
- [ ] `changelog` entry added with reason and date

---

## STOP Conditions

Stop and do not promote if any of the following are true:

- Replay validation fails (alert count mismatch or unexpected alerts)
- Any contract validation fails
- `confidence` < 0.65 (cannot advance to shadow)
- `confidence` < 0.75 without documented false-positive rate review
- `mitre_attack` is empty for endpoint or threat-intel rules
- `validation_evidence` is missing for staged_active promotion
- Owner has not reviewed
- `xdr_rule_registry_validate.py` returns any failure

---

## Endpoint-Specific Gates

Endpoint rules have additional constraints:
- May not advance to `staged_active` until endpoint domain soak completes
- See `docs/architecture/endpoint-shadow-correlation-plan.md` for all required gates
- `output_topic` must remain `xdr.alerts.shadow.endpoint` until full endpoint promotion

## Threat-Intel Enrichment Gates

IOC enrichment rules have additional constraints:
- IOC store must be operational and loaded with validated threat feeds
- IOC match rules must not produce alerts for known-benign values (tested via benign fixture)
- `output_topic` must remain `xdr.alerts.shadow.endpoint` until integration with active alert pipeline is approved
