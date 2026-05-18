# Naming Conventions Reference

Last updated: 2026-05-17

Consistent naming across code, UI, API, and documentation.

---

## ID Format Conventions

| Entity | Format | Example |
|---|---|---|
| Investigation | `INV-YYYY-NNNNN` | `INV-2026-00001` |
| Response Plan | `RP-YYYY-NNNNN` | `RP-2026-00001` |
| Export Audit | `EXP-YYYY-NNNNN` | `EXP-2026-00001` |
| Resilience Run | `RES-YYYY-NNNNN` | `RES-2026-00001` |
| Alert | `xdr-{fingerprint[:40]}` | `xdr-abc123...` |
| Incident | `xdr-inc-{sha256[:24]}` | `xdr-inc-abc...` |
| trace_id | UUID v4 hex | `550e8400-e29b-41d4-a716...` |

---

## Investigation State Names

These names must be consistent across: DB column values, PHP constants, Blade views, API JSON responses, and documentation.

| State | Meaning | Display Label |
|---|---|---|
| `new` | Just created | New |
| `triaged` | Reviewed, severity set | Triaged |
| `investigating` | Active investigation | Investigating |
| `escalated` | Raised to senior analyst | Escalated |
| `contained_manual` | Analyst documented external action | Contained (Manual) |
| `resolved` | Root cause identified and addressed | Resolved |
| `false_positive` | Ruled benign | False Positive |
| `closed` | Investigation closed | Closed |

**Note:** `contained_manual` = analyst *documented* that they manually performed external containment. Zero system execution. This must never be relabeled as "contained" (which implies automated action).

---

## Response Plan State Names

| State | Meaning |
|---|---|
| `draft` | Being assembled |
| `pending_approval` | Submitted for reviewer approval |
| `approved` | Approved by reviewer |
| `rejected` | Rejected, returned to draft |
| `completed_documented` | Analyst documented manual completion |
| `cancelled` | Abandoned |

---

## Response Action Type Names

All action types use `recommend_` prefix. Never `execute_` or `action_`.

| Type | Description |
|---|---|
| `recommend_reset_password` | Suggest password reset |
| `recommend_revoke_session` | Suggest session revocation |
| `recommend_disable_user` | Suggest account disable |
| `recommend_block_ip` | Suggest IP block |
| `recommend_block_domain` | Suggest domain block |
| `recommend_monitor_only` | Suggest enhanced monitoring |
| `recommend_collect_forensics` | Suggest forensic collection (advisory_only=true) |
| `recommend_remove_persistence` | Suggest persistence cleanup (advisory_only=true) |
| `recommend_isolate_host` | Suggest host isolation (advisory_only=true) |
| `recommend_notify_stakeholders` | Suggest stakeholder notification |

---

## Entity Type Names

Lowercase, singular, consistent across DB `entity_type` column, API JSON, and UI labels.

| Type | Examples |
|---|---|
| `user` | alice@example.com |
| `host` | web-server-01 |
| `ip` | 10.0.0.1 |
| `domain` | evil.example.com |
| `process` | powershell.exe |
| `file_hash` | sha256:abc123... |
| `alert` | xdr-alert-id |
| `incident` | xdr-inc-id |
| `trace` | trace-uuid |

---

## Security Hardening Event Types

Lowercase with underscores. Used in `security_hardening_events.event_type`.

| Type | When recorded |
|---|---|
| `auth_failure` | Invalid/missing `X-Internal-Service-Token` |
| `signature_failure` | Invalid event envelope signature |
| `secret_warning` | Dev default or missing secret detected |
| `startup_validation` | `SecretsValidationService::validateAndRecord()` run |
| `audit_violation_attempt` | Attempt to delete/update append-only table |

---

## Resilience Scenario IDs

Lowercase with underscores. Stable identifiers used as route params and DB values.

```
broker_restart               consumer_reconnect
opensearch_unavailable       clickhouse_unavailable
alert_writer_restart         incident_builder_restart
delayed_consumer             backpressure_accumulation
dlq_replay_recovery          partial_service_outage
endpoint_ingestion_interrupt signature_verification_failure
invalid_auth_token           replay_under_degraded_state
```

---

## Route Naming Conventions

| Pattern | Example | Notes |
|---|---|---|
| `{resource}.index` | `resilience.index` | List/dashboard |
| `{resource}.show` | `resilience.show` | Single item |
| `{resource}.history` | `export.history` | Audit/history list |
| `{resource}.run` | `resilience.run` | Trigger action |
| `{resource}.store` | `investigation.store` | Create |
| `{resource}.transition` | `investigation.transition` | State change |
| `api.{resource}.index` | `api.entities.index` | API list |
| `api.{resource}.show` | `api.entities.show` | API single |
| `api.internal.{action}` | `api.internal.status` | Internal service API |

---

## API Response Field Names

Consistent across all JSON responses.

| Field | Type | Meaning |
|---|---|---|
| `trace_id` | string(120)\|null | Cross-service correlation ID |
| `actor_key` | string\|null | Alert actor (email or identifier) |
| `alert_type` | string | Detection rule identifier (UPPER_SNAKE_CASE) |
| `severity` | string | low\|medium\|high\|critical |
| `risk_score` | float | 0.0–10.0 |
| `risk_level` | string | low\|medium\|high\|critical |
| `status` | string | State machine current state |
| `scenario_type` | string | active\|simulation |
| `export_type` | string | investigation\|response_plan\|entity_risk\|trace\|incident_bundle |
| `export_format` | string | json\|markdown\|html |
| `event_type` | string | Hardening event type (see above) |

---

## Alert Type Naming (Detection Rules)

UPPER_SNAKE_CASE. Domain prefix + behavior description.

Examples:
- `IDENTITY_MFA_FAILURE_BURST`
- `CLOUD_SUSPICIOUS_OBJECT_ACCESS`
- `ENDPOINT_SCHEDULED_TASK_PERSISTENCE` (shadow-only)
- `THREAT_INTEL_IOC_MATCH` (shadow-only)

---

## Dashboard and UI Label Conventions

| Context | Label | Avoid |
|---|---|---|
| Navigation | "Security" | "Hardening", "SecOps" |
| Navigation | "Resilience" | "Fault Tolerance", "HA" |
| Navigation | "Export" | "Reports", "Downloads" |
| Navigation | "Detection" | "Rules Engine" |
| Response plan disclaimer | "Advisory only" | "Simulated", "Pending" |
| Shadow entity indicator | "(shadow — advisory)" | "(inactive)", "(disabled)" |
| Endpoint detection | "Shadow observation" | "Endpoint protection" |
| Contained state | "Contained (Manual)" | "Contained", "Auto-contained" |

---

## Grafana Dashboard UIDs

Stable UIDs used for import. Do not change after creation.

| Dashboard | UID |
|---|---|
| Trace Flow | `trace-flow-v1` |
| Endpoint | `endpoint-xdr-v1` |
| Entity Risk | `xdr-entity-risk-v1` |
| Investigation Workflow | `xdr-investigation-v1` |
| Response Planning | `xdr-response-v1` |
| Export & Reporting | `xdr-export-reporting-v1` |
| Security Hardening | `xdr-security-hardening-v1` |
| Resilience & Recovery | `xdr-resilience-v1` |

---

## Documentation Terminology

| Use | Avoid | Reason |
|---|---|---|
| "hybrid detection platform" | "full XDR", "autonomous XDR" | Scope accuracy |
| "rule-based correlation" | "AI detection", "ML detection" | Technical accuracy |
| "shadow-only" | "disabled", "not implemented" | Architectural precision |
| "advisory-only" | "simulated response", "pending execution" | Execution safety clarity |
| "research prototype" | "production-ready", "enterprise-grade" | Academic honesty |
| "near real-time" | "real-time" | Avoids sub-ms latency claim |
| "append-only" | "immutable", "write-once" | Standard event sourcing term |
| "replay-safe" | "idempotent" (in user-facing text) | More descriptive |
| "projection layer" | "source of truth" | Entity graph is derived, not authoritative |
| "staged_active" | "live", "production" | Preserves migration state semantics |
| "shadow-only" | "deprecated", "disabled" | Shadow rules are active in code, just isolated |
