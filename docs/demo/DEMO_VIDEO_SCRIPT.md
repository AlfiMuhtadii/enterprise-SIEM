# Demo Video Script

Last updated: 2026-05-17

---

## 1. Opening (30 seconds)

> Modern security operations face two problems: too much telemetry, and too little structure for acting on it. This platform centralizes detection, organizes investigation, and guides response — without autonomous execution that bypasses analyst judgment.

Show:
- `/soc` dashboard — incident count, maturity overview, service separation status
- Point out: "Go handles 77,000+ events/second. Laravel is the analyst control plane."

---

## 2. Detection Pipeline (1 minute)

Navigate to a recent alert in `/soc/api/alerts`.

Show:
- alert_type from identity/cloud correlation (e.g., IDENTITY_MFA_FAILURE_BURST)
- severity, actor_key, trace_id
- evidence chain

Say:
> Telemetry flows through a Go ingestion gateway, normalizer, and correlation worker. Identity and cloud/SaaS detection is staged-active — validated with a 6-hour soak test at 77,000 events/second. Endpoint detection is shadow-only — visible but not generating active alerts.

Show `/detection` — detection rule governance:
- 12 staged_active rules (identity/cloud)
- 9 shadow rules (endpoint — hard gate prevents promotion)

Say:
> Promotion requires a domain-specific soak PASS. The gate is enforced in code — you can't override it without evidence.

---

## 3. Entity Graph (1 minute)

Navigate to `/entity`, search for a user or IP.

Show:
- entity profile: type, first/last seen, observation count
- `/entity/{id}/timeline` — sorted observation history
- `/entity/{id}/graph` — adjacency graph (bounded depth traversal)

Say:
> The entity graph is a projection layer built from existing alert and incident data. It's read-only — security_alerts is the authoritative source. The graph lets analysts pivot from an alert to every related entity without manual correlation.

Navigate to `/entity-risk` — risk dashboard.

Click an entity → risk breakdown view.

Say:
> Risk scoring is deterministic and explainable. Same data produces the same score. Shadow indicators from endpoint detection are visible here but advisory-only — they don't trigger response.

---

## 4. Investigation Workflow (1.5 minutes)

Navigate to `/investigations`.

Show investigation queue, create a new investigation.

Walk through state machine:
- new → triaged (set severity)
- triaged → investigating (assign to analyst)
- investigating → escalated (add escalation note)

Show:
- investigation event log — every state change, note, assignment is recorded
- assignment history — previous assignments deactivated, not deleted
- analyst notes and artifacts

Say:
> The state machine is enforced at the service layer — you can't skip states. The audit trail is append-only. No event is ever updated or deleted. If an investigation is reassigned, the previous assignment is preserved with a timestamp.

---

## 5. Response Planning (1 minute)

Navigate to `/response-plans/recommendations`.

Show recommended actions for a high-risk entity.

Create a plan, submit for approval, approve.

Show the plan in `approved` state.

Say:
> Recommendations are generated deterministically based on entity type, risk factors, and alert history. No LLM, no external API, no randomness. Every action type is prefixed recommend_ — there is no execute_. Approving a plan documents the analyst's intent. The platform records it, but takes no action.

Show disclaimer visible on every plan:
> "Recommendations are advisory-only and were not automatically executed by the platform."

---

## 6. Export Center (45 seconds)

Navigate to `/exports`.

Export an investigation as JSON, then as HTML.

Open the export history — show EXP-YYYY-NNNNN IDs.

Say:
> Every export is logged in an append-only audit table. The export is automatically redacted — passwords, tokens, and secrets are replaced with [REDACTED] before rendering. The HTML is self-contained with no external CDN dependencies, suitable for offline review or court documentation.

---

## 7. Trace Investigation (45 seconds)

Navigate to `/traces`, search by trace_id.

Show:
- event timeline across services (ingestion → normalizer → correlation → alert-writer → incident-builder)
- correlated alerts and incidents for this trace
- sensitive data redacted in the UI

Say:
> Every event carries a trace_id from ingestion to the final incident. This view lets analysts follow a single request through the entire distributed pipeline. Sensitive payload data is redacted at the presentation layer — the database is never mutated.

---

## 8. Security Hardening & Resilience (1 minute)

Navigate to `/security/hardening`.

Show:
- secret validation warnings (dev defaults, missing secrets)
- service auth config table (which services have tokens configured)
- audit table counts (append-only integrity)

Run in terminal:
```powershell
php artisan security:validate-secrets
```

Navigate to `/resilience`.

Show scenario grid — 14 scenarios.

Run `signature_verification_failure` scenario.

Show findings: non-destructive, logged, trace preserved.

Say:
> Internal service authentication uses time-bounded HMAC tokens. Event signatures are deterministic and replay-safe. Invalid signatures are logged but never destructive — the pipeline continues. We've validated 14 failure scenarios including broker restart, consumer reconnect, DLQ recovery, and endpoint shadow isolation.

---

## 9. XDR Scenario Runner (45 seconds)

Navigate to `/scenario`.

Run `failed_login_burst` in stub mode.

Show scenario run evidence, detection result, recommendation.

Say:
> The Scenario Runner validates that detection rules fire correctly. In real pipeline mode, it publishes actual telemetry events to the ingestion gateway and polls the database for correlation results. This is how we confirm that a rule change doesn't break detection before promoting to production.

---

## 10. Closing (30 seconds)

> This is a distributed detection and investigation platform built with a strangler migration pattern — no big bang rewrite. Go handles the high-throughput pipeline, Python handles event orchestration and AI, and Laravel is the analyst control plane. Every component is operationally validated, every audit trail is append-only, and every architectural boundary is tested.

Final stats to mention:
- 591 automated tests, all green
- 6h soak: 562 million events, zero failures, p95 < 81ms
- 24 detection rules, 12 active
- 14 resilience scenarios validated
- Zero autonomous actions — analyst is always in the loop
