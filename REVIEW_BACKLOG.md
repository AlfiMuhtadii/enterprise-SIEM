# Pending Hardening & Cleanup Tasks (Backlog)

This file tracks all pending security hardening, refactoring, and documentation alignment tasks.
It is synchronized with GitHub Issues. Developers/writing agents (e.g., Claude) should resolve these tasks.

---

## Open Backlog Tasks

| Task ID | Title | File / Component | Priority | Status |
|---|---|---|---|---|
| **ENT-SEC-NO-TLS-INTERNAL** | [Enterprise BLOCKER] No TLS/mTLS on any internal hop (Pandaproxy/OpenSearch plaintext HTTP, Postgres no sslmode, static bearer tokens) — supersedes deferred ARCH-MTLS-SEC; mandatory at enterprise bar | `services/*`, `app/Services/InternalAuthService.php`, DB DSNs | High | Proposed (re-open ARCH-MTLS-SEC) |
| **ENT-TENANCY-NO-DB-ENFORCEMENT** | [Enterprise BLOCKER] Isolation is app-layer `where('tenant_id')` only (ASSET-TENANT-OVERWRITE proves it leaks); no DB RLS. Supersedes deferred TENANT-ENFORCE-RLS — mandatory for multi-tenant SaaS | `app/Services/TenantBoundaryService.php`, Postgres RLS | High | Proposed (re-open TENANT-ENFORCE-RLS) |
| **ENT-REL-SIMULATED-HA** | [Enterprise BLOCKER] HA/scale/DR "PASS" is computed, not measured on real cluster (SIM-LAYER Track B + HA-DRILL-01). "Too heavy for laptop" invalid at enterprise bar — run on real staging before any availability claim | `app/Services/EnterpriseScaleHaService.php` et al., `docker-compose.ha.yml` | High | Proposed (re-open Track B + HA-DRILL-01) |
| **ENT-DETECT-ML-NOT-LIVE** (investigated — original proposed fix is wrong integration point) | [Enterprise product-claim BLOCKER] Model scores **HTTP-request** features (`status`/`latency_ms`/`has_sql_keywords`/...), confirmed identical to `security_events` (Laravel's `SecurityRequestLogger`), NOT correlation-worker's identity/cloud/SaaS telemetry — wiring it into correlation-worker as originally proposed would score data it was never trained on. The real rule+ML hybrid already exists as working code (`scripts/realtime_detector_consumer.py`) but isn't in `docker-compose.yml` at all, and it writes directly to the **active** `security_alerts` table with no soak gate — deploying it live as-is risks silently activating an unvalidated new alert domain | `scripts/realtime_detector_consumer.py`, `docker-compose.yml`, `app/Http/Middleware/SecurityRequestLogger.php` | High | Deferred — needs advisory-first soak plan, see detail section |
| **ENT-SDLC-NO-SUPPLYCHAIN** (base-image digest pinning done; SBOM/scan/sign remain) | [Enterprise SDLC] Python `requirements.txt` already pin exact versions (`==`) and Go services have zero external deps (no `go.sum` needed) — that part was already fine. The real gap was all 6 Dockerfiles floating on mutable tags (`python:3.12-slim`, `golang:1.26-alpine`, `alpine:3.22`); now pinned to resolved digests (`@sha256:...`, fetched live from the Docker Hub registry API). Still missing: SBOM generation (syft/cyclonedx), image vuln scan gate (trivy), signed builds (cosign) — none of these tools are available in this environment | `services/*/Dockerfile`, CI | Medium | Proposed (reduced) |
| **IDENTITY-SSO-MFA** (TOTP MFA done; SSO/SAML/OIDC federation remains) | [Enterprise BLOCKER — re-ranked] Per-user opt-in TOTP now implemented (`TotpService`, RFC 6238, dependency-free — verified against the RFC's own published test vector, not just self-consistency). Login gates on a 6-digit code when a user has enabled it; existing password-only login is unaffected for everyone else. Still missing: real SSO/SAML/OIDC federation to a corporate IdP (Okta/Azure AD) — needs a real external IdP to configure and test against, which this environment doesn't have; enforcing MFA as *mandatory* for specific roles/routes (`soc:response.*`/`soc:admin.*`) is also still a policy decision, not yet wired | `app/Services/TotpService.php`, `app/Http/Controllers/Auth/MfaController.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `routes/auth.php` | High | Proposed (reduced) |
| **OBS-OTEL-TRACING** | [Enterprise-XDR — re-ranked High] No standards-based distributed tracing across polyglot services (OpenTelemetry / W3C traceparent); required for enterprise SLA support | `services/*/main.*`, `app/Http/Middleware/*`, ingestion→normalizer→correlation→alert-writer→incident-builder | High | Proposed |
| **ML-SERVE-ONLINE** | [Enterprise-XDR] Superseded by ENT-DETECT-ML-NOT-LIVE (re-ranked to product-claim blocker); trained multiclass LR model is offline-script-only, not in live detection path | `scripts/train_ai_detector.py`, `scripts/realtime_detector_consumer.py`, `services/correlation-worker/main.go` | High | Proposed (see ENT-DETECT-ML-NOT-LIVE) |
| **TECH-EOL-UPGRADE** | [Tech Currency] PHP `^8.1` (security EOL 2025-12), Laravel `^10.10` (EOL), Sanctum `^3.3` — running on end-of-life runtime/framework is not enterprise-supportable | `composer.json` | High | Proposed |
| **CODE-STRUCT-DECOMPOSE** (correlation-worker + normalizer-worker fully decomposed; alert-writer-service started — fingerprint/alert_id extracted; Pandaproxy/Kafka transport in all three intentionally remains) | [Structure/Maintainability] `correlation-worker/main.go`: 1165 lines (was 2950, 60% reduction) — shadow-rule engine in `internal/shadowrules`/`internal/ioc`. `normalizer-worker/main.go`: 732 lines (was 1223, 40% reduction) — normalizer family in `internal/normalize`. `alert-writer-service/main.py`: **1402 lines** (corrected count — prior "1277" was stale) — extracted `fingerprint()`/`alert_id()` (the core alert dedupe/identity hashing that produces the durable `alert_id` written to `security_alerts`/OpenSearch) into a new sibling module `alert_identity.py`, following the exact precedent already set by this service's own `xdr_event_contracts.py` (a standalone pure-function module with zero FastAPI/Pydantic/DB coupling). These two functions had **zero isolated unit test coverage** despite being business-critical — `fingerprint()` was only ever called as a test *helper* to compute an expected value for unrelated assertions, `alert_id()` had no test references at all. Now covered by 19 new direct unit tests (evidence_ids-vs-event_ids fallback, scalar-to-list coercion, sort-order independence, actor_key/ip/"unknown" fallback chain, alert_id override-vs-derived branch, and one test asserting the hash matches an independently-computed SHA-256 reference value). Dockerfile fixed to `COPY alert_identity.py` (same class of bug as normalizer-worker's Dockerfile fix — this service already copies files by explicit name, not a whole directory, so the omission would have broken the Docker build the moment the file existed; caught and fixed before it shipped, confirmed via `docker compose config --quiet`). Remaining in alert-writer-service: everything else is stateful (Postgres/OpenSearch/Kafka-REST/DB connections) and stays in `main.py` per the established pattern — this was the single easiest, lowest-risk extraction, not a full decomposition of the service. Pandaproxy/Kafka transport in all three services remains untouched (tightly coupled to Worker/connection state — high-risk, consistently left in place across all three decompositions this session) | `services/correlation-worker/main.go`, `services/normalizer-worker/main.go`, `services/alert-writer-service/main.py`, `services/alert-writer-service/alert_identity.py` | Medium | Proposed (reduced) |
| **CONNECTOR-FRAMEWORK** | [Capability — MOST ABSENT] No generic log-ingestion/connector framework — no syslog receiver, no CEF/LEEF parser, no cloud-native log connectors (CloudTrail/GuardDuty/O365). The "X" breadth of XDR is missing; ingestion is only the signed HMAC gateway + a few hand-coded typed normalizers | `services/ingestion-gateway`, `services/normalizer-worker`, new `services/log-connector-*` | High | Proposed |
| **DATA-TIERING** (phase 1 done — archive-before-delete; warm/cold tiers remain) | [Capability] `SecurityRetentionCommand` now archives every pruned row to gzip JSONL (`SecurityRetentionArchiveService`) before deleting — data is no longer simply gone, just no longer hot/queryable. Still missing: warm tier (ClickHouse, months-scale searchable), cold tier (object storage archival/restore), and this local-gzip archive isn't itself searchable — needs live ClickHouse/S3-compatible infra to build and verify further | `app/Services/SecurityRetentionArchiveService.php`, `app/Console/Commands/SecurityRetentionCommand.php`, ClickHouse, object storage | Medium | Proposed (reduced) |
| **META-MODULE-RATIONALIZE** | [Off-track / Scope creep] ~32 of 90 services are self-referential readiness/certification/maturity/evidence-freeze/soak-sim modules (incl. 4× StabilityEvidenceFreeze, overlapping soak services) — huge maintenance surface, not XDR capability | `app/Services/*Readiness*.php`, `*Certification*.php`, `*EvidenceFreeze*.php`, `*Soak*.php`, `*Maturity*.php` | Medium | Proposed |
| **SIM-LAYER-REALITY-GATE** (Track B only — Track A done) | [Dummy → must be real] Track A (labelling) done: all 35 HA/scale/chaos/soak/pilot validation-run tables now carry `is_simulated`/`evidence_basis`. Remaining: Track B — back the key validators (HA failover, scale, soak) against a real multi-node harness (`docker-compose.ha.yml`) so they produce *measured*, not just *computed*, evidence | `app/Services/EnterpriseScaleHaService.php`, `TelemetryScalePilotService.php`, `SoakChaosValidationService.php`, `PilotExecutionService.php`, `docker-compose.ha.yml` | High | Proposed (reduced) |
| **ARCH-KAFKA-NATIVE** | [Enterprise throughput — promoted from footnote 2026-07-06] Go workers talk to Redpanda via Pandaproxy HTTP REST (serialization + no compression + per-op TCP) instead of native binary Kafka (franz-go/sarama, port 9092). At enterprise throughput this is a real latency/CPU ceiling, not a demo nicety. GATE: live-pipeline verifier + offset-recovery/poison-DLQ regression per CLAUDE.md | `services/{ingestion-gateway,normalizer-worker,correlation-worker}/main.go` | High | Proposed (staged — needs live-pipeline validation) |
| **PERF-REST-POLL** | [Enterprise throughput — promoted 2026-07-06] Consumer loops long-poll Pandaproxy REST `/records`; native Kafka consumer removes the REST round-trip overhead. Bundle with ARCH-KAFKA-NATIVE (same transport rewrite). GATE: live-pipeline verifier | `services/{normalizer-worker,correlation-worker}/main.go` | Medium | Proposed (staged) |
| **PERF-REST-REBALANCE** | [Enterprise reliability — promoted 2026-07-06] Stable consumer instance IDs to avoid REST rebalance storms on restart; touches the hardened consumer-offset-recovery path (see CONSUMER-GROUP-EPHEMERAL, done). GATE: live-pipeline verifier | `services/{alert-writer,incident-builder}-service/main.py`, Go workers | Medium | Proposed (staged) |
| **PERF-GO-LIMITER** | [Enterprise hot-path — promoted 2026-07-06] Channel+ticker token bucket (IG-2) → atomic time-delta limiter to cut per-request contention at high sustained RPS. Low-risk but must not regress the recently hardened IG-2/IG-DOS/RATE-LIMIT-DOS logic. GATE: Go race tests (needs gcc) | `services/ingestion-gateway/main.go` | Medium | Proposed (staged) |
| **PERF-GO-OVERCONCURRENT** | [Enterprise hot-path — promoted 2026-07-06] `normalizeBatch` allocates goroutines+channels per poll batch → GC churn at high RPS; reuse a bounded worker pool. GATE: Go bench + race tests | `services/normalizer-worker/main.go` | Medium | Proposed (staged) |
| **ARCH-DB-SPLIT** | [Enterprise scale — promoted 2026-07-06] Alert/telemetry write-path lands on OLTP Postgres; route high-volume telemetry to ClickHouse (OLAP) and reserve PG for relational/SOC state so dashboards don't contend with ingest. Infra redesign — needs live ClickHouse + load test | `services/alert-writer-service/main.py`, ClickHouse, PG | High | Proposed (staged — infra) |
| **ARCH-DISCOVERY** | [Enterprise infra — promoted 2026-07-06] Static hostnames only; multi-node needs DNS/service discovery + internal LB. Belongs with a real multi-node deploy (ties ENT-REL-SIMULATED-HA / HA-DRILL-01) | `docker-compose*.yml`, deploy manifests | Medium | Proposed (staged — infra) |
| **AI-KB-SEMANTIC** | [Enterprise AI — promoted 2026-07-06] Qdrant + cosine ranking path exists (`SocKnowledgeRetriever::retrieveQdrant`); only a live transformer embedding model is missing (currently offline pseudo-embeddings). Needs a bundled/served embedding model — conflicts with offline-first default, so gate behind a flag | `app/Support/SocKnowledgeRetriever.php`, embedding service | Medium | Proposed (staged — needs ML infra) |
| **AI-KB-FEED-INGEST** | [Enterprise AI — promoted 2026-07-06] No live MITRE/RSS threat-intel feed ingest into the KB. Re-scope as a bundled offline dataset import to preserve offline-first posture rather than a live network dependency | `app/Services/*`, KB seeding | Low | Proposed (staged) |
| **CAP-DETECT-AS-CODE-SIGMA** (compiler done — metadata/catalog import only, see reduced scope note) | [Power — detection coverage multiplier] `SigmaImportService` + `detection:import-sigma` artisan command compile SigmaHQ YAML into shadow-only registry entries (domain/severity/MITRE/field-mapping), always `status=shadow`, never `staged_active`. **Reduced scope, discovered during research**: `registry.v1.json` is a metadata/governance catalog everywhere in this codebase — no existing rule's detection logic is driven by the JSON (all 133 are hand-coded Go/Python) — so this compiler produces a catalog entry with the original Sigma `detection:` block preserved for later hand-implementation, not auto-generated executable detection logic. That gap is consistent with how every other rule already works, not a shortfall unique to this importer | `app/Services/SigmaImportService.php`, `app/Console/Commands/DetectionImportSigmaCommand.php` | High | Proposed (reduced) |

> **This file tracks only pending/open tasks.** Completed tasks live in `REVIEW_COMPLETED.md`; rejected/deferred in `REVIEW_REJECTED.md`.
>
> **Classified out (2026-06-29):** `EDR-EXEC-02` and `AI-CONF-BANDS` → REJECTED (forbidden: automated active containment). `TENANT-ENFORCE-RLS` → DEFERRED (gated by RLS_DECISION_RECORD + GAP-002/003). See REVIEW_REJECTED.md.
>
> **Promoted from footnote → actionable rows (2026-07-06):** the BATCH-DEFER-2026-07-01 cluster is now open task rows above (`ARCH-KAFKA-NATIVE`, `PERF-REST-POLL`, `PERF-REST-REBALANCE`, `PERF-GO-LIMITER`, `PERF-GO-OVERCONCURRENT`, `ARCH-DB-SPLIT`, `ARCH-DISCOVERY`, `AI-KB-SEMANTIC`, `AI-KB-FEED-INGEST`; `ARCH-MTLS-SEC` is covered by `ENT-SEC-NO-TLS-INTERNAL`). They remain **staged** — each carries its CLAUDE.md validation gate (live-pipeline verifier for hot-path/transport, Go race tests, or real infra) and must not be batched. This makes them visible/triage-able instead of hidden in a note.

---

## Proposed Task: IDENTITY-SSO-MFA — Enterprise SSO (SAML/OIDC) + MFA for analyst auth

- **Priority:** High
- **Component:** `app/Http/Controllers/Auth/*`, `config/auth.php`, `routes/auth.php`, `composer.json`
- **Finding:** Analyst authentication is Laravel Breeze **session + password only**
  (`AuthenticatedSessionController`, `RegisteredUserController`). No Socialite, SAML,
  OIDC/OpenID Connect, WebAuthn, or TOTP/2FA package is installed (`composer.json` ships
  only `laravel/sanctum`). A real SOC/XDR must federate analyst identity to a corporate IdP
  (Okta/Azure AD/Google) and enforce MFA — ironically the platform *ingests* IdP events as
  telemetry but cannot *authenticate its own operators* against one.
- **Why enterprise-relevant:** SSO + MFA on a privileged SOC console (which approves
  response commands) is table-stakes for commercial XDR (SOC 2 / ISO 27001 access control).
- **Proposed fix:** Add an OIDC/SAML broker (`socialiteproviders/*` or `laravel/fortify`
  with 2FA) behind `XDR_SSO_ENABLED=false` default; enforce MFA middleware on
  `soc:response.*` and `soc:admin.*` routes. Keep the demo password login as fallback so
  the thesis defense walkthrough is unaffected.
- **Safety:** Pure auth hardening; no forbidden boundary touched.
- **Progress (2026-07-11):** TOTP half done, dependency-free (no `laravel/fortify`/
  `pragmarx/google2fa` needed — implemented RFC 6238 directly against stdlib `hash_hmac`,
  ~100 lines). New `TotpService`: `generateSecret()`, `provisioningUri()` (standard
  `otpauth://` URI, compatible with any authenticator app), `verify()` (±1 period drift
  tolerance). **Verified against the RFC's own published test vector** (not just
  generate-then-verify self-consistency, which would pass even with a subtly wrong HMAC/
  truncation): RFC 6238 Appendix B's secret/counter/SHA1 combination independently
  cross-checked in Python, confirmed the RFC's published 8-digit code, then confirmed this
  service's 6-digit truncation matches the last 6 digits of that same value. New `users`
  columns `mfa_secret` (encrypted at rest via Laravel's `encrypted` cast), `mfa_enabled`,
  `mfa_confirmed_at`. New `MfaController` (`setup`/`enable`/`disable`/`challenge`/`verify`)
  and a 2-step login: `AuthenticatedSessionController::store()` now checks
  `$user->mfa_enabled` after password auth succeeds — if enabled, immediately reverts the
  `Auth::attempt()` login and redirects to a code-entry step; only a verified code completes
  `Auth::login()`. Per-user opt-in (`mfa_enabled` defaults `false`), so the demo/walkthrough
  password-only login is completely unaffected unless a user explicitly enables MFA on their
  own account. +21 tests (10 `TotpServiceTest` incl. the RFC vector check, 11 `MfaTest`
  covering the full enable/disable/challenge flow plus the "unaffected without MFA" case).
  **Not done**: real SSO/SAML/OIDC federation (needs an actual external IdP to configure and
  test against — out of reach in this environment) and *mandatory* enforcement on specific
  roles/routes (a policy decision for later, not a technical blocker — the capability exists,
  nothing currently requires any user to turn it on).

## Proposed Task: OBS-OTEL-TRACING — Standards-based distributed tracing across polyglot services

- **Priority:** Medium
- **Component:** `services/ingestion-gateway`, `services/normalizer-worker`,
  `services/correlation-worker`, `services/alert-writer-service`,
  `services/incident-builder-service`, Laravel HTTP middleware
- **Finding:** No OpenTelemetry / W3C `traceparent` propagation across the pipeline. Grep
  for `opentelemetry|otel|jaeger|zipkin|traceparent` returns nothing in `services/`, `app/`,
  `config/`; Python `requirements.txt` files carry no OTel SDK. Ad-hoc
  `trace_id`/`source_event_id` lineage exists, but no standards-based span context a
  collector (Tempo/Jaeger) can stitch end-to-end. OBS-029 (done) covers **metrics/SLO**, not
  distributed traces.
- **Why enterprise-relevant:** Root-causing latency/failure across 6 polyglot hops
  (Go→Go→Go→Python→Python→Laravel) is impractical without end-to-end trace correlation.
- **Proposed fix:** Add the OTel SDK per service, propagate `traceparent` from the ingestion
  gateway through Redpanda headers into every downstream hop, emit spans to an OTLP collector
  (compose `observability` profile, off by default), and map existing `trace_id` onto span
  context.
- **Safety:** Observability only; append-only lineage guarantees unaffected.
- **Progress (2026-07-10):** Phase 1 done — W3C Trace Context (level 1) generation, parsing,
  and hop-to-hop propagation across the 3 Go pipeline services (`ingestion-gateway` →
  `normalizer-worker` → `correlation-worker`), implemented **dependency-free** (stdlib
  `crypto/rand`/`regexp` only, no OTel SDK) following this codebase's established pattern for
  RFC-shaped protocol logic (see `TotpService`'s direct RFC 6238 implementation). New
  `internal/traceparent` package (identical content in all 3 service modules — this codebase
  has no shared Go workspace/module, so each service already duplicates small pure packages;
  same precedent as `internal/cef`) exposes `Generate()` (fresh root: version `00`, 16-byte
  trace-id, 8-byte span-id, sampled flag), `Parse()` (validates version/hex-length/non-zero
  trace-id+span-id per the level-1 spec), `NewChildSpan()`, and `Propagate(inbound string)
  string` (parse-or-generate-root, then mint a child span) — one function every hop calls.
  **Deliberately additive, not a replacement**: investigated the existing `trace_id` field
  first and found it is a free-form string used as an analyst-facing correlation tag across
  ~90 services/tables, frequently domain-prefixed (`soc-`, `sla-`, `esc-`, `dlq-`, `trace-`,
  ...) — reformatting it to strict W3C shape would be an invasive, high-risk rename touching
  dozens of unrelated call sites for no benefit. Instead `traceparent` ships as a **new**
  sibling field: `ingestion-gateway`'s `publish()` sets it (generate-if-absent, same shape as
  the existing `newTraceID()` pattern) before every Pandaproxy produce; `normalizer-worker`'s
  `Event()` dispatcher was restructured (direct per-type `return Foo(raw)` branches → capture
  into `dispatch()` + a single post-processing propagation step) so propagation is applied once
  generically instead of edited into all 13 per-telemetry-type normalizer functions;
  `correlation-worker`'s `makeAlert()` picks the first non-empty `Traceparent` among
  contributing events (mirroring its existing `TraceID` selection logic) and propagates a child
  span onto the `Alert`. Confirmed (via research) that Pandaproxy's JSON v2 envelope does
  support real Kafka headers but **nothing in this codebase uses them** — so `traceparent`
  travels as a plain body field, consistent with how every other lineage field
  (`trace_id`/`tenant_id`/`demo_run_id`) already travels, not as a header (avoids introducing
  a second, inconsistent propagation mechanism). +37 new Go tests (11 per service's
  `internal/traceparent` — generate/parse/reject-malformed/reject-all-zero/reject-uppercase/
  child-span-preserves-trace-id/propagate-with-valid-or-missing-or-invalid-inbound — plus 2
  `normalize_test.go` cases and 2 `main_test.go` `makeAlert` cases wiring it end-to-end).
  `go build`/`go vet`/`go test ./...` clean across all 3 services; full existing suites
  unaffected (0 regressions). **Not done**: Python services (`alert-writer-service`,
  `incident-builder-service`) propagation, Laravel-side exposure, and — the actual payoff —
  wiring to a real OTLP collector/OTel SDK so a tool like Tempo/Jaeger can stitch spans; this
  phase only produces standards-shaped span context, it does not yet emit or collect spans.
  **Not run**: a live end-to-end trace-id-continuity smoke test across the running pipeline —
  Docker daemon unavailable in this environment, the same standing limitation noted throughout
  this session's other infra-adjacent work.
- **Progress (2026-07-10, later):** Phase 2 done — Python-side propagation across
  `alert-writer-service` and `incident-builder-service`, extending the chain one hop further
  (`correlation-worker` → `alert-writer-service` → `incident-builder-service`). New
  `traceparent.py` module, byte-for-byte algorithmic mirror of the Go `internal/traceparent`
  package (`generate()`/`parse()`/`new_child_span()`/`propagate()`, stdlib `re`+`secrets` only,
  no OTel SDK), duplicated identically in both service directories — same precedent as
  `xdr_event_contracts.py` already being duplicated across both (confirmed via `diff`, zero
  drift). `AlertPayload`/`WriteRequest`/`BuildRequest` gained an optional `traceparent` field
  alongside the existing `trace_id`. In `alert-writer-service`: `normalize_records()` backfills
  `traceparent` from the batch envelope exactly like it already does for `trace_id`;
  `process_alerts()` computes `tp.propagate(alert.traceparent or traceparent)` per alert — a
  **new child span every hop**, not a passthrough like `trace_id` — and both the `alerts.created`
  payload and its `envelope()` call carry it. In `incident-builder-service`: `aggregate()` (which
  already derives `trace_id` as the first non-empty value among a group's contributing alerts)
  now derives `traceparent` the same way, then propagates one new child span per aggregated
  incident; `process_alerts()` reads it back off the incident dict into the `incidents.updated`
  envelope. `xdr_event_contracts.py`'s `envelope()` gained an optional `traceparent` param,
  included in the returned envelope dict — deliberately **excluded** from `stable_event_id()`'s
  hash material, since a fresh span-id on every `propagate()` call would otherwise break
  idempotent event-id generation for an identical replay. **Deliberately out of scope this
  pass**: no Postgres column / persistence for `traceparent` on `security_alerts`/
  `security_incidents` (unlike `trace_id`) — adding one would pull in a migration and the full
  Laravel test gate for a field whose actual payoff is OTLP collector consumption from the live
  event stream, not ad-hoc SQL querying; documented as future scope alongside the OTLP wiring
  itself. +30 new Python tests: 28 direct-import pure-function tests for `traceparent.py`
  (mirroring the Go test suite: generate/parse/reject-each-invalid-shape/child-span/propagate,
  duplicated per service matching `test_alert_identity.py`'s direct-import precedent — no
  fastapi/pydantic stubbing needed since the module has zero heavy deps) + 2 new
  `aggregate()`-level wiring tests using `SimpleNamespace` fake alerts (duck-typed, bypassing
  the heavy `BaseModel = object` stub the existing harness uses — same technique
  `test_alert_identity.py`'s `make_alert()` helper already established) confirming trace-id
  preserved / span-id changed end-to-end through the real aggregation function, not just the
  isolated module. Per-directory suites clean: `tests/alert_writer` 82/82,
  `tests/incident_builder` 52/52, zero regressions. **Note**: the combined top-level
  `python -m unittest discover -s tests` run fails (1 failure + 77 errors) both **before and
  after** this change (verified via `git stash`) — a pre-existing test-isolation issue between
  same-named `main` modules across the `alert_writer`/`incident_builder` test directories when
  discovered together, not something this task introduced; per-directory discovery (the pattern
  CLAUDE.md's own Python test convention already uses for `tests/endpoint_agent`) is the
  supported/passing invocation. **Still open**: Laravel-side exposure and the OTLP collector/SDK
  wiring itself — this remains context-production-only, nothing consumes these spans yet.
- **Progress (2026-07-10, phase 3):** Laravel-side exposure done — the pipeline (Go+Python) side
  was already complete; this closes the one remaining hop explicitly named in the original
  Component list (`Laravel HTTP middleware`). New `TraceparentService` (`app/Services/`), a PHP
  algorithmic mirror of the same `generate()`/`parse()`/`newChildSpan()`/`propagate()` contract
  as the Go `internal/traceparent` packages and Python `traceparent.py` modules — pure,
  dependency-free (`random_bytes`/`preg_match` only). Wired into the existing global
  `SecurityRequestLogger` middleware (already runs on every request per `Kernel.php`): reads an
  inbound `traceparent` request header if present, propagates a child span via the same
  `TraceparentService::propagate()` every pipeline hop already uses, sets it on the response as
  a `Traceparent` header (alongside the existing `X-Request-Id` pattern), and adds it to the
  structured `SecurityLogger::log('http_request', ...)` payload — so an analyst's own browser
  request into the SOC console can now be linked to the same trace as the pipeline events they're
  investigating. Deliberately placed **before** the middleware's existing
  `shouldIgnore()`/internal-path early-return (mirroring where `X-Request-Id` is already set),
  since trace propagation is a cross-cutting concern independent of the security-detector's
  authenticated-internal-path exclusion — verified with a dedicated test that the header is
  present even on `/soc` (an ignored path). No DB schema change — consistent with the Python
  phase's decision to keep `traceparent` production-only rather than pulling in a migration for
  a field whose real payoff is OTLP collector consumption. +17 new tests: 13 pure unit tests
  (`tests/Unit/TraceparentServiceTest.php`, no Laravel bootstrap needed, mirrors the Go/Python
  test suites exactly — generate/parse/reject-each-invalid-shape/child-span/propagate) + 4
  HTTP-level feature tests (`SecurityRequestLoggerTraceparentTest`): valid-generated-header when
  none sent, child-span-of-inbound when one is sent, invalid-inbound still yields a valid
  generated header (never blocks the response), and the ignored-path header-still-present case.
  Full `php artisan test --parallel --recreate-databases` run confirmed green (see CLAUDE.md
  gate). **Still genuinely open** (unchanged): wiring to a real OTLP collector/OTel SDK across
  all 6 hops — every hop (Go, Python, now PHP) produces standards-shaped span context, but no
  service anywhere emits or collects an actual OTel span yet.

## Proposed Task: ML-SERVE-ONLINE — Multiclass LR model is offline-only, not in the live detection path

- **Priority:** Medium-High
- **Component:** `scripts/train_ai_detector.py`, `scripts/realtime_detector_consumer.py`,
  `services/correlation-worker/main.go`, `storage/app/ai_detector_model.pkl`
- **Finding:** The trained multiclass logistic-regression model (`ai_detector_model.pkl`) is
  referenced **only** by offline `scripts/` (`train_ai_detector`, `golden_replay_test`,
  `realtime_detector_*_consumer`, `replay_detector_from_db`). **No live service loads it** —
  grep for `ai_detector_model|\.pkl|joblib|pickle.load` across `services/` returns nothing;
  the live `correlation-worker` is rule-based only. So the headline "Hybrid detection:
  rule-based + ML logistic regression" is, in the *running* pipeline, effectively rule-based;
  the ML half runs only in batch/replay tooling.
- **Why enterprise-relevant:** "Hybrid ML + rules" is a core XDR value proposition and a
  thesis claim. To be true online it must be served as a first-class **inference** step —
  as a shadow/advisory scorer first (consistent with shadow-soak posture), never as an
  autonomous-response trigger.
- **Proposed fix:** Stand up a lightweight model-serving endpoint (extend `ai-rag-service`
  or a new `ml-inference-service`) that loads the `.pkl` and scores normalized
  identity/cloud/SaaS events; correlation-worker calls it and attaches the score as
  **advisory evidence** (shadow-only). Any ML-derived active signal stays behind the
  domain-specific 6h soak gate. Distinct from ML-DRIFT-03 (retraining/drift), which remains
  deferred as resource-heavy.
- **Safety:** Advisory/shadow scoring only — no autonomous response; respects soak gates.
- **Investigated 2026-07-10 — proposed fix above is based on a wrong integration point,
  re-scoped/deferred rather than implemented:** `train_ai_detector.py`'s feature vector
  (`status`, `latency_ms`, `has_sql_keywords`, `has_script_payload`, `path_len`,
  `is_admin_path`, `failed_1m/5m/10m`, `req_1m/5m`, ...) is verified to be **HTTP-request**
  features — confirmed identical to what `app/Http/Middleware/SecurityRequestLogger.php`
  captures into the `security_events` table (Laravel's own local SIEM-lite pipeline). This
  model was never intended to score `correlation-worker`'s normalized identity/cloud/SaaS
  telemetry (structurally different fields — `user`/`action`/`cloud_account` vs.
  `status`/`path`/`latency_ms`); wiring it into correlation-worker as proposed would produce
  scores on data the model was never trained on, which is worse than no ML claim at all.
  The **real** rule+ML hybrid for `security_events` already exists as working code —
  `scripts/realtime_detector_consumer.py` (confirmed: a genuine Pandaproxy consumer with a
  `while True` loop, loads the `.pkl`, combines rule thresholds + ML prediction) — but it is
  **not wired into `docker-compose.yml` at all** (grep confirms zero references), so nothing
  keeps it running; a developer must launch it by hand. Also confirmed it writes directly to
  `security_alerts` (line ~845) and `security_responses` (line ~868) — the **active** alert
  path, with no advisory/shadow gate. Making this genuinely live (not just "restore the
  script") means either (a) deploying it as a real docker-compose service that starts writing
  active alerts for a domain (web-request/HTTP-attack detection) that is not part of the
  documented active identity/cloud/SaaS scope and has never been soak-validated — a real risk
  of breaching the Forbidden Changes list's spirit on domain promotion — or (b) redirecting
  its output to an advisory-only table first (matching the `advisory_findings`
  shadow-alert-consumer pattern used elsewhere) and soak-validating before any promotion,
  which is a properly-sized dedicated task, not a quick wire-up. **Deferred, not implemented
  this pass** — needs an explicit decision on (a) vs. (b) plus a soak plan before code changes.

## Proposed Task: TECH-EOL-UPGRADE — Runtime/framework are at or past end-of-life

- **Priority:** High
- **Component:** `composer.json`
- **Finding — verified:** `php: ^8.1`, `laravel/framework: ^10.10`, `laravel/sanctum: ^3.3`.
  PHP 8.1 active support ended 2023-11 and **security support ends 2025-12** (i.e. EOL as of
  now, 2026-07). Laravel 10's bug-fix window closed 2024-08 and security window ~2025-02.
  Sanctum 4 is current. An "enterprise full XDR" cannot ship on an unsupported PHP/framework —
  no security patches, and dependency ecosystem drift compounds over time.
- **Why enterprise-relevant:** Supportable, patchable runtime is a baseline compliance
  requirement (SOC 2 / vendor security review). This is the single biggest "is this
  enterprise-grade" signal in the dependency manifest.
- **Proposed fix:** Staged upgrade — PHP 8.1→8.3, Laravel 10→11 (then evaluate 12), Sanctum
  3→4 — each behind its own branch with the full `migrate:fresh --force && php artisan test`
  gate (4548 tests) green before merge. Laravel 11 changes the bootstrap/config structure, so
  do it as a dedicated effort, not bundled.
- **Safety:** Framework upgrade only; no product-boundary change. Must keep the full suite green.

## Proposed Task: CODE-STRUCT-DECOMPOSE — Decompose monolithic single-file services

- **Priority:** Medium
- **Component:** `services/correlation-worker/main.go` (2944 lines),
  `services/normalizer-worker/main.go` (1181), `services/alert-writer-service/main.py` (1277)
- **Finding — verified:** The most complex service, `correlation-worker`, is a **single
  2944-line `main.go`** in `package main` — rule evaluation, IOC lookup+cache, cross-domain
  correlation, Pandaproxy consume/produce, DLQ publishing, and HTTP metrics all in one file.
  The normalizer (1181) and alert-writer (1277) follow the same single-file shape. This is
  workable for a demo but is a maintainability/testability liability at enterprise scale:
  hard to unit-test units in isolation, high merge-conflict surface, and no separation between
  transport, detection logic, and persistence.
- **Why enterprise-relevant:** A "full XDR" correlation engine is the crown-jewel component;
  it should be package-decomposed (e.g. `internal/rules`, `internal/ioc`, `internal/kafka`,
  `internal/correlate`) so detection logic is independently testable and reviewable.
- **Proposed fix:** Refactor into cohesive packages **behind the existing tests** — extract
  detection/correlation logic from transport and I/O, no behavior change. Gate on the existing
  Go tests (`main_test.go`, `ioc_cache_test.go`) plus a live-pipeline verifier run per CLAUDE.md
  (correlation is on the hot path). Pure structural refactor; do it incrementally, one seam at a
  time, not a big-bang rewrite (respects the Architecture Direction Lock).
- **Safety:** Structure only; detection behavior and event contracts must be byte-identical.
  Live-pipeline verifier required because this touches the correlation hot path.
- **Progress (2026-07-09):** Seam 1 — IOC lookup+cache extracted to
  `internal/ioc` (own package: `Cache`, `Lookup`, `Severity`, `Confidence`, `Configure`; the
  3 call sites in `ruleIOCIPMatch`/`ruleIOCDomainMatch`/`ruleIOCHashMatch` now call
  `ioc.Lookup(...)` etc.). `ioc_cache_test.go`'s 5 tests moved into the new package
  (`internal/ioc/ioc_test.go`) unchanged in behavior, +2 new tests for `Severity`/`Confidence`
  defaults. `main.go` 2950→2836 lines.
- **Progress (2026-07-10):** Seam 2 — the `EndpointAlert` foundation (`EndpointAlert` type,
  `epStr`/`epInt64`/`makeEndpointAlert`/`dedupeEndpointAlerts`) plus the network shadow rules
  (`correlateNetworkShadowAll` + 9 `ruleNetwork*` funcs, the most isolated rule group — zero
  relation to the process/endpoint rule tables) extracted to `internal/shadowrules`, all
  exported (`EpStr`, `EpInt64`, `MakeEndpointAlert`, `DedupeEndpointAlerts`,
  `CorrelateNetworkShadowAll`). Since the foundation type is used by all 44 rule functions in
  the file (not just the 9 that physically moved), every remaining rule signature in `main.go`
  was mechanically updated to `shadowrules.EndpointAlert` — done via a one-off Python script
  (deleted after use) for the ~40 call-site substitutions, not by hand, then verified by
  `go build` (caught and fixed one double-prefix bug from an overlapping replace pattern:
  `[]EndpointAlert{a}` → briefly `[]shadowrules.shadowrules.EndpointAlert{a}`, fixed to
  `[]shadowrules.EndpointAlert{a}`). No tests existed for this code before (0 tests covered any
  `rule*`/`correlate*` function or `EndpointAlert` construction) — added 9 new tests for the
  foundation helpers + a representative slice of the network rules (not all 9 rules
  individually — that's a larger, separate test-writing effort). `main.go` 2836→2438 lines;
  `internal/shadowrules/shadowrules.go` 423 lines. Also fixed a real bug found while checking
  Docker build parity: `services/correlation-worker/Dockerfile` only did `COPY main.go ./`,
  which would have broken the build the moment `internal/ioc` was added in seam 1 (never caught
  because the Docker daemon is unavailable in this environment) — added
  `COPY internal ./internal`, which covers both `internal/ioc` and the new
  `internal/shadowrules` automatically. `go build`/`go vet`/`go test ./...` clean across all 3
  packages (`main`, `internal/ioc`, `internal/shadowrules`). **Not run**: the live-pipeline
  verifier and an actual `docker build` (Docker daemon unavailable) — recommended before the
  next deploy, especially given the Dockerfile bug this session already found once.
- **Progress (2026-07-10, later same day):** Seam 3 — cross-domain shadow correlation
  (`correlateEndpointShadowCrossDomain` + 5 `ruleCrossDomain*`/`ruleCrossHost*` funcs) moved
  into `internal/shadowrules` too. Discovered mid-move that this block (and several
  not-yet-moved rule groups) share 3 static tables — `linuxShellNames`/`downloaderNames`/
  `lolbinNames` — with the "core endpoint rules" group still in `main.go`; moved those 3 tables
  to `internal/shadowrules` as exported vars (`LinuxShellNames`/`DownloaderNames`/`LolbinNames`)
  and updated the ~11 remaining `main.go` usage sites to the qualified form, rather than leaving
  a duplicate/diverging copy in each package. +4 tests for the cross-domain rules
  (`CorrelateEndpointShadowCrossDomain` empty/aggregate cases, `ruleCrossDomainIdentityEndpoint`
  fire/no-fire). `main.go` 2438→2185 lines; `internal/shadowrules/shadowrules.go` now 686 lines.
  `go build`/`go vet`/`go test ./...` clean across all 3 packages. **Not run**: live-pipeline
  verifier / `docker build` (daemon unavailable, same standing limitation).
- **Progress (2026-07-11):** Seam 4 — streaming shadow rules moved (fully self-contained, no
  shared-table surprises this time). Seam 5 — core endpoint rules (`ruleParentChildProcess`,
  `rulePowershellEncoded`, `ruleSuspiciousTempFile`, `ruleFailedLoginBurst`, `ruleSuspiciousDNS`,
  `ruleSuspiciousOutbound`, `ruleScheduledTaskPersistence`, `ruleNewServicePersistence`,
  `ruleC2BeaconPattern`) plus their static tables, exported as `shadowrules.RuleXxx` since
  they're still called from `correlateEndpointShadow` at that point. Seam 6 — behavioral
  visibility + behavioral analytics + threat-hunting-behavioral rules (13 more functions) *and*
  the `correlateEndpointShadow` aggregator itself moved together (it had zero remaining
  dependency on `Worker`/IOC once its sub-groups were all in `shadowrules`), exported as
  `CorrelateEndpointShadow`. One extraction-script bug caught immediately by `go build:
  undefined: shadowrules` — internal references to the already-moved `LinuxShellNames`/
  `DownloaderNames`/`LolbinNames` tables still had a leftover `shadowrules.` prefix from when
  they lived in a different package; fixed directly (13 occurrences). +9 more tests across
  seams 4-6 (`ruleParentChildProcess`, `ruleFailedLoginBurst`, `ruleParentChildChain`,
  `CorrelateEndpointShadow` empty/filter/aggregate cases). **Result: `main.go` 2185→1165 lines
  (a 60% reduction from the original 2950)** — the entire endpoint/network shadow-rule engine
  now lives in `internal/shadowrules`/`internal/ioc`. What's left in `main.go` is transport
  (Worker/HTTP/Pandaproxy), the active identity/cloud correlation path (out of scope for this
  item), and 4 thin IOC-bridging functions kept as intentional composition-root glue. `go
  build`/`go vet`/`go test ./...` clean across all 3 packages throughout. **Not run**: live-
  pipeline verifier / `docker build` — same standing limitation every pass this session.
  Considering this backlog item's rule-engine scope **effectively closed**; remaining work
  (Pandaproxy transport decomposition, normalizer-worker, alert-writer-service) is different
  enough in shape that it's tracked as ongoing rather than "more seams of the same kind."
- **Progress (2026-07-10):** `incident-builder-service/main.py` decomposed — the one remaining
  Python service that had never had a seam extracted (`normalizer-worker` and
  `alert-writer-service` were already done in earlier passes). New `incident_aggregation.py`:
  `alert_entities()`/`group_key()`/`incident_id_for()`/`aggregate()` — the alert-to-incident
  grouping/identity logic, pure with zero FastAPI/Pandaproxy/DB dependency (confirmed: only
  references the module-local `SEVERITY_RANK` constant, a `now_iso()` helper, and `traceparent`)
  — extracted the same way `alert_identity.py` was extracted from `alert-writer-service/main.py`
  in an earlier pass; `AlertPayload`/`BuildRequest` type hints intentionally use `Any` rather
  than importing the Pydantic model back from `main.py`, avoiding a circular import (same
  technique `alert_identity.py` already uses). `now_iso()` moved into the new module too (its
  only true dependency was `aggregate()`'s two fallback timestamps) and is now imported back
  into `main.py` rather than duplicated, since it's a pure 2-line helper with zero
  `main.py`-specific dependency — eliminating a within-service duplication rather than
  introducing one. `main.py` 708→645 lines; also dropped 2 now-unused imports (`hashlib`,
  `datetime`/`timezone`) left behind by the extraction. **This logic had zero isolated unit
  test coverage before extraction** (only reachable indirectly through the full FastAPI
  request path) — +25 new direct-import tests (`test_incident_aggregation.py`, no
  fastapi/pydantic stubbing needed, `SimpleNamespace` fake alerts) covering entity resolution
  from evidence lists vs. actor_key/ip fallback (and that both are *unconditionally* included
  alongside evidence entities — a real behavior worth pinning down, caught by a test that
  initially asserted the opposite and had to be corrected against the actual code, not the
  other way around), `group_key`'s alert-type-family + first-sorted-entity derivation,
  `incident_id_for` determinism, and `aggregate`'s severity/confidence/timeline-ordering/
  mitre-dedup/domain-dedup/trace_id-and-tenant_id-first-non-empty assembly (including a
  corrected test for `confidence`'s `default=0.5` — that default only fires for an *empty*
  generator, which can't happen since `aggregate()` always receives >=1 alert, so a single
  alert with `score=None` correctly yields `confidence=0.0`, not `0.5`; the test's first draft
  wrongly asserted `0.5` and was fixed against the real semantics after the test run caught it).
  Also fixed a **latent Docker build bug** discovered while checking Dockerfile parity for this
  task (the exact same bug class already caught once for the Go services' `internal/` packages
  earlier this session): `alert-writer-service/Dockerfile` and `incident-builder-service/
  Dockerfile` use explicit per-file `COPY` lines rather than copying the whole directory, and
  neither had been updated to `COPY traceparent.py` when phase 2 of OBS-OTEL-TRACING added it —
  meaning the image build has been broken since that commit, never caught because the Docker
  daemon is unavailable in this environment. Fixed both Dockerfiles (`traceparent.py` added to
  both; `incident_aggregation.py` added to `incident-builder-service`'s). Per-directory Python
  suites clean: `tests/alert_writer` 82/82, `tests/incident_builder` 77/77 (52 pre-existing this
  session + 25 new this pass), zero regressions. **Not run**: an actual `docker build` to
  verify the Dockerfile fix — Docker daemon unavailable, the same standing limitation.
- **Progress (2026-07-10, ThreatHuntingService):** The single biggest decomposition win found
  in the codebase this session — `app/Services/ThreatHuntingService.php` was **2524 lines**, of
  which **~1150 lines (46%)** was a single `DOMAIN_FIELDS` constant (a security-critical
  field/operator allowlist, guarding "NEVER allows raw SQL expressions, field injection, or
  unsupported domains" per the class's own docblock) plus its companion `SUPPORTED_DOMAINS`
  constant and `validateQueryFilters()` validator — pure data + pure validation logic, zero
  Eloquent/DB dependency, extracted verbatim into a new `ThreatHuntQueryAllowlist` class.
  `ThreatHuntingService` 2524→1145 lines (55% reduction); `validateQueryFilters()` kept as a
  1-line delegating passthrough (`ThreatHuntQueryAllowlist::validate(...)`) so every existing
  caller's signature is unchanged, and `supportedDomains()`/the two `DOMAIN_FIELDS[...]`
  lookup sites in `executeQuery()`/`queryAlerts()` were repointed at the new class.
  **Found and preserved a real backward-compatibility hazard before it could break anything**:
  `DOMAIN_FIELDS` was `private const` (zero external references, confirmed by grep — safe to
  move outright), but `SUPPORTED_DOMAINS` was `public const` and is referenced by **25 other
  files** (`ThreatHuntController` plus 24 test files spanning almost every meta-module test
  suite, since hunt-domain-count tracking is threaded through this codebase's "N hunt domains"
  convention) — moving it without a compatibility path would have broken all 25 with an
  undefined-constant fatal. Added `public const SUPPORTED_DOMAINS =
  ThreatHuntQueryAllowlist::SUPPORTED_DOMAINS;` back on `ThreatHuntingService` (PHP allows a
  class constant's value to reference another class's constant; verified this resolves
  correctly at runtime, not just parses, via the full `ThreatHuntingQueryEngineTest` suite) —
  zero of the 25 external call sites needed to change. Extraction itself performed via `sed`
  line-range extraction + a small Python rewrite script (not the Edit tool) given the sheer
  size (~1370 lines moved) made manual old_string/new_string matching impractical; every step
  verified with `php -l` and the real test suite, not just visual inspection. **This allowlist
  had zero isolated unit test coverage before extraction** (only reachable indirectly through
  the full `executeQuery()`/RefreshDatabase path) — +11 new direct-import tests
  (`ThreatHuntQueryAllowlistTest`, plain `PHPUnit\Framework\TestCase`, no DB, matching the
  `TotpServiceTest`/`TraceparentServiceTest` precedent for pure services): valid pass,
  unsupported-domain rejection, non-allowlisted-field rejection, disallowed-operator-for-an-
  allowed-field rejection, multi-filter validation (checks every filter, not just the first),
  plus two **structural sanity checks over the entire 1150-line data structure** that weren't
  practically writable before this was its own reviewable unit: every `DOMAIN_FIELDS` key has a
  matching `SUPPORTED_DOMAINS` entry (no dead/orphaned allowlist blocks), and every field's
  operator list is non-empty (an empty list would make that field permanently unusable via
  `in_array` against `[]`) — both passed cleanly, confirming the allowlist data itself has no
  latent inconsistencies. Full `php artisan test --parallel --recreate-databases` run (see
  CLAUDE.md's Laravel gate) confirmed green across the entire suite, including all 25 external
  `SUPPORTED_DOMAINS` call sites and the full 44-test `ThreatHuntingQueryEngineTest` suite.

## Proposed Task: CONNECTOR-FRAMEWORK — Generic log-ingestion / connector & parser framework

- **Priority:** High (the single biggest capability gap)
- **Component:** `services/ingestion-gateway`, `services/normalizer-worker`, new
  `services/log-connector-*` + a parser registry
- **Finding — verified:** The "X" in XDR is breadth of sources, and that breadth is missing.
  Grep for `syslog|CEF|LEEF|cloudtrail|guardduty|filebeat|logstash|connector framework` finds
  **no inbound log ingestion** — only (a) the endpoint agent tailing local `/var/log/syslog` as
  a DNS *fallback*, and (b) Laravel's own `SyslogUdpHandler` as an *output* log driver. All real
  ingestion goes through the single HMAC-signed `/v1/ingest` gateway and a handful of
  hand-coded typed normalizers (`endpoint-v1`, `dns-v1`, `identity`, `saas`, …). There is **no
  syslog/CEF/LEEF receiver, no cloud-native log connectors (AWS CloudTrail/GuardDuty, Azure,
  O365 mgmt API, GCP), and no pluggable parser framework** to onboard a new source without
  writing a bespoke Go normalizer.
- **Why enterprise-relevant:** A real XDR/SIEM onboards dozens of source types via
  connectors + a parser DSL (grok/CEF/JSON path). Without this, every new data source is a
  code change to the Go pipeline — the platform can't scale its coverage.
- **Proposed fix (staged, advisory-safe):** Add a connector service that accepts syslog
  (UDP/TCP 514) and a CEF/LEEF parser that maps into the existing `telemetry.raw` contract, plus
  a config-driven parser registry (source-type → field map) so new sources are onboarded by
  config, not code. Start with syslog + CEF (covers most network/appliance vendors). All events
  still flow through the existing normalize→correlate shadow path — no new active domain, soak
  gates unchanged.
- **Safety:** Ingestion only; feeds the existing shadow pipeline; no active-domain expansion.
- **Progress (2026-07-09):** Phase 1 done — syslog + CEF, the pair explicitly called out to
  "cover most network/appliance vendors." New service `services/log-connector-syslog`: a
  pure `internal/cef` package (`Parse()`) parses ArcSight CEF's 7 pipe-delimited header fields
  plus its space-separated `key=value` extension, correctly honoring header `\|`/`\\` escaping
  and extension `\=`/`\\`/`\n` escaping, and stripping either an RFC3164 or RFC5424 syslog
  envelope preceding the `CEF:` marker (verified against fixtures of both). `main.go` runs UDP
  and TCP listeners (newline-delimited framing; RFC6587 octet-counting framing intentionally
  not supported — documented scope limit, not a silent gap), maps a parsed CEF message into the
  existing generic `telemetry.raw` contract as `telemetry_type=syslog_cef` (promoting
  `src/dst/spt/dpt/proto/act/suser` to the same top-level aliases the other normalizers already
  recognize, while preserving the full extension verbatim under `cef_extension` so no
  vendor-specific field is lost), and forwards batches to the existing `ingestion-gateway`
  `/v1/ingest` endpoint signed with the exact same HMAC-SHA256 sigv2 scheme
  (`sha256=HMAC(secret, ts + "." + body)`) the gateway itself verifies — no new trust path.
  Lines that fail CEF parsing are **not dropped**: they forward as `telemetry_type=syslog_raw`
  with the raw line preserved, so an unrecognized source is still visible for analyst review.
  `normalizer-worker` gained a matching `CefSyslog()` handler (`internal/normalize/normalize.go`),
  dispatched on `telemetry_type=syslog_cef`, marked `advisory_only: true`; `syslog_raw` needs no
  new handler since the existing generic fallback envelope already covers it (ts/telemetry_type/
  event_type are always populated by the connector). New service wired into `docker-compose.yml`
  under the existing `strangler` profile (`docker compose config --quiet` exit 0), pointing at
  `ingestion-gateway` by container DNS name. All events still land in the existing
  normalize→correlate shadow path — no new active alert domain, no rule changes this pass.
  +18 Go tests (10 `internal/cef` — bare/RFC3164-prefixed/RFC5424-prefixed CEF, no-extension,
  escaped-pipe-in-header, escaped-equals-in-extension, non-CEF rejection, malformed-header
  rejection, non-numeric-version rejection, multi-space extension values; 8 `main_test.go` —
  field-promotion mapping, empty-Name fallback, raw-fallback envelope, dispatch+blank-line
  skip, HMAC signature cross-checked against an independently recomputed reference value,
  signed-forward-to-mock-ingestion-gateway via `httptest.Server`, non-2xx forward error
  counting, batch-size-triggered flush) + 1 new `normalizer-worker` dispatch test +
  `TestCefSyslogMarksAdvisoryAndPreservesExtension`. `go build`/`go vet`/`go test ./...` clean
  for both `log-connector-syslog` and `normalizer-worker`. **Not done**: LEEF parser, a
  config-driven parser registry for onboarding arbitrary sources without code, and cloud-native
  connectors (CloudTrail/GuardDuty/O365/GCP) — CEF's syntax is materially different from LEEF's,
  and a generic parser-registry DSL is a separable, larger design effort; both remain open scope
  for a later phase. **Not run**: an actual `docker build`/live UDP-to-pipeline smoke test —
  Docker daemon unavailable in this environment, the same standing limitation noted throughout
  this session's other infra-adjacent work.
- **Progress (2026-07-10):** Phase 2 done — LEEF parser, the second format explicitly called
  out as open scope after phase 1. New pure `internal/leef` package in the same
  `services/log-connector-syslog` service (no new service needed): `Parse()` handles both
  LEEF 1.0 (`LEEF:Ver|Vendor|Product|ProductVersion|EventID|Extension`, implicit tab-delimited
  extension) and LEEF 2.0 (`LEEF:Ver|Vendor|Product|ProductVersion|EventID|Delimiter|Extension`,
  explicit delimiter field — accepts a literal single character or a hex byte written as
  `x09`/`0x09`, decoded via `strconv.ParseUint(..., 16, 8)`), honors `\|`/`\\` header escaping
  and `\<delim>`/`\\` extension escaping, and strips a preceding syslog envelope the same way
  `internal/cef` does. `main.go`'s `processLine()` now tries CEF first, then LEEF, then falls
  back to `syslog_raw` (markers are mutually exclusive by construction, so trying both is safe
  and cheap); a parsed LEEF message maps to `telemetry_type=syslog_leef`, promoting LEEF's
  common extension field names (`src`/`dst`/`srcPort`/`dstPort`/`proto`/`usrName`/`cat`) to the
  same top-level aliases the CEF path and other normalizers already use, while preserving the
  full extension verbatim under `leef_extension`. `normalizer-worker` gained a matching
  `LeefSyslog()` handler dispatched on `telemetry_type=syslog_leef`, `advisory_only: true`,
  structurally identical to `CefSyslog()`. Connector `/metrics` gained a `parsed_leef` counter
  alongside `parsed_cef`/`parsed_raw`. +23 new Go tests (13 `internal/leef` — LEEF 1.0 tab
  delimiter, LEEF 2.0 literal delimiter, LEEF 2.0 `x09` and `0x09` hex delimiter forms, syslog
  envelope stripping, escaped-pipe-in-header, escaped-delimiter-in-extension-value, no-extension,
  non-LEEF rejection, unsupported-version rejection [only 1.x/2.x accepted], too-few-header-
  fields for both v1 and v2 shapes, invalid-delimiter-field rejection; 4 `main_test.go` —
  field-promotion mapping, empty-EventID fallback, `processLine` CEF-vs-LEEF-vs-raw 3-way
  dispatch; 2 in `normalizer-worker`'s `normalize_test.go`). `go build`/`go vet`/`go test ./...`
  clean across both services; every phase-1 CEF/raw test still passes unmodified (0
  regressions). **Still open, unchanged from phase 1**: a config-driven parser registry for
  onboarding arbitrary sources without a code change, and cloud-native connectors
  (CloudTrail/GuardDuty/O365/GCP) — both remain separable, larger efforts for a later phase.
  **Not run**: `docker build`/live UDP-to-pipeline smoke test — Docker daemon unavailable in
  this environment, the same standing limitation noted throughout this session's other
  infra-adjacent work.
- **Progress (2026-07-10, phase 3):** Config-driven parser registry done — the remaining half
  of the original proposed fix ("a config-driven parser registry (source-type → field map) so
  new sources are onboarded by config, not code"). New `internal/registry` package in
  `services/log-connector-syslog`: `SourceDefinition{Name, Marker, TelemetryType,
  EventTypeField, FieldMap}` loaded from a JSON file (`Load()`, empty path → empty registry,
  zero-config default); `Match()` finds the first definition whose `Marker` substring appears
  in the line (same technique CEF/LEEF already use for their own markers); `Parse()` extracts
  space-separated `key=value` pairs after the marker (reusing the same key-boundary regex
  technique as `internal/cef`'s extension parser, kept as an independent implementation rather
  than a cross-package dependency, since the registry is deliberately generic/decoupled from
  CEF's specific grammar) and promotes only the keys named in `FieldMap` to output field names,
  while preserving every key found — mapped or not — verbatim in `Extension`. `processLine()`
  dispatch order is now CEF → LEEF → registry match → `syslog_raw` fallback. New
  `mapRegistryToEvent()` writes `FieldMap`-promoted fields directly under their **configured
  canonical names** (`source_ip`, `action`, ...) — the same names the normalizer's existing
  generic fallback envelope already recognizes — so onboarding a genuinely new source through
  this registry requires **zero `normalizer-worker` code changes**, only a JSON config entry;
  this is the actual "by config, not code" payoff the original finding asked for. Shipped a
  real example, `parsers.sample.json` (a `generic_appliance_fw` source: marker `APPFW:`,
  promoting `src/dst/spt/dpt/proto/act/suser`), proven to work end-to-end via a test that loads
  the exact shipped file and parses a full RFC3164-prefixed sample line through it — not just a
  synthetic in-memory config. `main.go` wired `XDR_SYSLOG_PARSER_REGISTRY` (optional path env
  var; unset → identical behavior to phase 2) and a `parsed_registry` `/metrics` counter.
  +19 new Go tests (12 `internal/registry` — load/empty-path/nil-registry/missing-file/
  invalid-JSON/marker-matching/field-promotion/extension-preservation/no-marker-in-line/
  unmapped-or-missing-source-key/no-event-type-field; 5 `main_test.go` —
  field-promotion+extension mapping, fallback-defaults, registry dispatch, no-match raw
  fallback, the shipped-sample-file end-to-end test; plus the existing `TestProcessLine*`
  tests updated for the new registry parameter). `go build`/`go vet`/`go test ./...` clean
  across all 4 packages (`main`, `internal/cef`, `internal/leef`, `internal/registry`); every
  phase-1/phase-2 CEF/LEEF/raw test still passes unmodified (0 regressions). README documents
  the config schema and the "why zero normalizer changes" reasoning. **Still open**:
  cloud-native connectors (CloudTrail/GuardDuty/O365/GCP) — a materially different integration
  shape (pull-based API polling, not a syslog receiver), remains a separate, larger effort.
  **Not run**: `docker build`/live UDP-to-pipeline smoke test — Docker daemon unavailable in
  this environment, the same standing limitation noted throughout this session's other
  infra-adjacent work.
- **Progress (2026-07-10, phase 4):** First cloud-native connector done — AWS CloudTrail, one of
  the four named (CloudTrail/GuardDuty/O365/GCP). **Deliberately scoped as file-based ingestion
  of already-exported logs, not live S3 API polling**: live polling needs AWS SigV4 request
  signing and real AWS credentials, neither exercisable or verifiable in this environment —
  rather than ship untested auth code, this phase stays honest about what it actually does.
  New standalone service `services/log-connector-cloudtrail`: a pure `internal/cloudtrail`
  package (`Parse()`) decodes CloudTrail's stable `{"Records":[...]}` export format,
  auto-detecting gzip via magic bytes (`0x1f 0x8b`) so the caller doesn't need to know whether a
  given file is compressed — CloudTrail's default S3 export format — or plain JSON; a record
  that fails to re-marshal into the typed struct is skipped rather than aborting the whole batch
  (one poison record must not block every other record in the same export file). `main.go`
  recursively walks a watch directory (`filepath.WalkDir`, matching CloudTrail's real nested S3
  layout `AWSLogs/<account>/CloudTrail/<region>/<year>/<month>/<day>/...`) on a poll interval,
  maps each record onto the **same canonical field names** the normalizer's generic fallback
  envelope already recognizes (`source_ip`/`user`/`cloud_account`/`action`/`result`/
  `event_source`) — so, exactly like the config-driven parser registry shipped in phase 3, this
  connector needs **zero `normalizer-worker` code changes**. Full original record preserved
  verbatim under `cloudtrail_record`. Restart-safe file tracking: processed file paths persist
  to `<watch-dir>/.cloudtrail-connector-state.json` (atomic write-then-rename) so a restart
  doesn't re-ingest every file already forwarded — CloudTrail export files are immutable once
  written, so "seen once, never re-read" is the correct semantics (no mtime tracking needed).
  Forwards via the same HMAC-SHA256 sigv2 scheme as the syslog connector — no new trust path.
  **Caught and fixed a real bug during test-writing, not left to a live run to discover**: the
  state file lives inside the same directory being scanned and is named `*.json`, so without an
  explicit skip it would have been picked up as a candidate CloudTrail export on every poll
  cycle, forever, spamming a parse-error log line indefinitely — a test that ran `scanOnce()`
  twice and asserted zero parse errors caught this before any deployment could hit it; fixed
  with an explicit `path == c.stateFile` skip in the walk callback, locked in with a dedicated
  regression test. Wired into `docker-compose.yml` under the existing `strangler` profile with a
  bind-mounted `./cloudtrail-logs` directory for an operator's `aws s3 sync` cron target
  (`docker compose config --quiet` exit 0). +15 Go tests (7 `internal/cloudtrail` — plain JSON,
  gzip-compressed, raw-field preservation, empty Records array, malformed JSON, malformed gzip,
  poison-record isolation; 8 `main_test.go` — field-promotion mapping incl. the
  userName→arn→principalId fallback chain, errorCode-as-result, tenant_id omission, HMAC
  signature cross-check, signed-forward via `httptest.Server`, non-2xx forward error, the
  already-processed-file rescan-skip behavior, the state-file-self-scan regression test, and a
  load/save state round-trip across a simulated restart). `go build`/`go vet`/`go test ./...`
  clean. **Still open, unchanged**: GuardDuty/O365/GCP connectors, and live S3 API polling for
  CloudTrail itself (would need SigV4 — a separate, larger effort requiring real AWS
  credentials this environment doesn't have). **Not run**: `docker build`/live smoke test —
  Docker daemon unavailable, the same standing limitation.
- **Progress (2026-07-10, phase 5):** Second cloud-native connector done — AWS GuardDuty, same
  file-based-not-live-API scope decision as CloudTrail (live `GetFindings`/`ListFindings`
  polling needs AWS credentials this environment can't exercise). New standalone service
  `services/log-connector-guardduty`. **The two connectors are NOT structurally identical
  despite both being "AWS, file-based, gzip-aware"**: GuardDuty's native "export findings"
  feature writes **NDJSON — one finding JSON object per line** — a materially different shape
  from CloudTrail's single `{"Records":[...]}` array per file, so this needed its own
  `internal/guardduty` parser rather than reusing `internal/cloudtrail`; a line that fails to
  decode is skipped (poison-line isolation), not fatal to the file. GuardDuty findings also
  have a genuinely harder field-mapping problem than CloudTrail: **there is no single
  `sourceIPAddress`-equivalent field** — GuardDuty findings carry different
  `Service.Action.*` shapes depending on finding `Type` (`NetworkConnectionAction`,
  `AwsApiCallAction`, `DnsRequestAction`, `KubernetesApiCallAction`, `PortProbeAction` with a
  nested details array, ...), so `Finding.RemoteIPAddress()` does **best-effort** extraction
  across the known common shapes and returns empty rather than guessing when a finding's type
  isn't one of them — documented explicitly as best-effort, not exhaustive, in both the
  docblock and the README (consistent with this connector framework's stated principle: no
  vendor-specific field is silently lost, since the full raw finding is always preserved under
  `guardduty_finding` regardless of what got promoted). Maps onto the same canonical field
  names the normalizer's generic fallback envelope already recognizes — zero
  `normalizer-worker` changes needed. **Proactively fixed, not just documented, the
  state-file-self-scan bug class this session already found once in the CloudTrail
  connector**: the state-file skip check was written into `scanOnce()` from the very first
  draft this time (not discovered via a failing test after the fact), and still locked in with
  the identical dedicated regression test used for CloudTrail, run twice per scan to confirm
  zero parse errors. Same restart-safe atomic-write state-file pattern, same HMAC sigv2
  forwarding, same directory-watcher skeleton. Wired into `docker-compose.yml` under
  `strangler` with a bind-mounted `./guardduty-findings` directory
  (`docker compose config --quiet` exit 0). +21 Go tests (11 `internal/guardduty` — plain
  NDJSON, gzip-compressed, raw-field preservation, blank-line skipping, poison-line isolation
  without aborting the file, empty input, malformed gzip, `RemoteIPAddress()` extraction from
  both `NetworkConnectionAction` and the nested-array `PortProbeAction` shape, and the
  no-match/no-action empty-string cases; 10 `main_test.go` — field-promotion mapping, empty
  source_ip when no action matches, HMAC cross-check, signed-forward, non-2xx error, rescan-skip,
  the state-file-self-scan regression test, state round-trip across a restart, and the
  extension-matching helper). `go build`/`go vet`/`go test ./...` clean. **Still open**:
  O365/GCP connectors, live AWS API polling for either AWS connector (needs SigV4 + real
  credentials). **Not run**: `docker build`/live smoke test — Docker daemon unavailable.
- **Progress (2026-07-10, phase 6):** Third cloud-native connector done — GCP Cloud Audit Logs,
  same file-based-not-live-API scope decision as CloudTrail/GuardDuty (live Cloud Logging API
  polling needs GCP credentials this environment can't exercise). New standalone service
  `services/log-connector-gcp-audit`. **A third distinct payload shape**, confirming the
  pattern that "cloud-native, file-based, gzip-aware" is not a single reusable format across
  providers: GCP log sinks also write NDJSON (like GuardDuty, unlike CloudTrail's single-array
  format), but the audit payload itself is nested inside a `protoPayload` object typed as
  `google.cloud.audit.AuditLog` (`@type` field) — genuinely different field names and nesting
  from both AWS formats, so `internal/gcpaudit` is its own parser, not a rename of the other
  two. Field mapping required deriving `result` from GCP's own convention rather than a direct
  field: an **empty** `protoPayload.status` object means the call succeeded, a **populated**
  one (non-zero `code` or non-empty `message`) means it failed — `HasErrorStatus()` checks
  both, tested for the empty-success case, the populated-failure case, and the field-absent
  case explicitly. `user`/`source_ip`/`cloud_account` are all nested nil-safe nested-map digs
  (`authenticationInfo.principalEmail`, `requestMetadata.callerIp`,
  `resource.labels.project_id`) via a small shared `digString()` helper, mirroring the same
  "walk a path through nested `map[string]any`, return empty on any missing hop" technique the
  GuardDuty connector already established for its multi-shape `Service.Action` extraction.
  **State-file-self-scan fix applied proactively again** (third time this exact fix has been
  written correctly from the first draft, after being discovered via a failing test only once,
  for CloudTrail), still locked in with the identical dedicated regression test. Wired into
  `docker-compose.yml` under `strangler` with a bind-mounted `./gcp-audit-logs` directory for
  an operator's `gcloud storage rsync`/`gsutil rsync` cron target (`docker compose config
  --quiet` exit 0). +23 Go tests (13 `internal/gcpaudit` — plain NDJSON, gzip, raw-field
  preservation, poison-line isolation, empty input, malformed gzip,
  `PrincipalEmail`/`CallerIP`/`ProjectID`/`ServiceName` extraction, both `HasErrorStatus()`
  branches plus the no-status-field case, and `ProjectID()`'s nil-labels-map safety; 10
  `main_test.go` — field-promotion mapping, error-result derivation, tenant_id omission, HMAC
  cross-check, signed-forward, non-2xx error, rescan-skip, the state-file-self-scan regression
  test, state round-trip across a restart, extension-matching helper). `go build`/`go vet`/
  `go test ./...` clean. **CONNECTOR-FRAMEWORK's originally-named cloud-native set
  (CloudTrail/GuardDuty/O365/GCP) is now 3 of 4 done** — only O365 Management Activity API
  remains, and it is a genuinely different integration shape from the 3 already built: O365
  audit logs are delivered via a pull-based Management Activity API (subscription + polling
  with continuation tokens), not a file-export-to-object-storage pattern, so it cannot reuse
  this phase's "watch a directory" connector skeleton the way CloudTrail/GuardDuty/GCP could —
  a real O365 connector needs actual OAuth/API-key credentials to test against, which this
  environment doesn't have; documented as the one remaining item requiring a materially
  different design, not just a fourth parser bolted onto the same skeleton. **Not run**:
  `docker build`/live smoke test — Docker daemon unavailable.

## Proposed Task: DATA-TIERING — Tiered long-term searchable log storage / retention lifecycle

- **Priority:** Medium
- **Component:** `app/Console/Commands/SecurityRetentionCommand.php`, ClickHouse, object storage
- **Finding — verified:** Retention today is a single daily prune
  (`security:retention --events-days=30 --alerts-days=90`) that **deletes** old data. There is
  no tiered lifecycle — grep for `cold storage|storage tier|object storage|archival|s3|glacier`
  finds nothing (the "tier" hits are unrelated rule-soak tiers). So data older than 30/90 days is
  simply gone; there is no warm/cold tier, no archival to object storage, no long-term
  searchable retention.
- **Why enterprise-relevant:** Enterprise XDR/SIEM must retain searchable history for months–
  years (incident forensics, compliance/audit — PCI 1yr, some regimes 7yr) with hot/warm/cold
  tiering to control cost. A 30/90-day hard delete is a demo posture, not enterprise.
- **Proposed fix:** Introduce a tiering lifecycle — hot (PostgreSQL/OpenSearch, recent) → warm
  (ClickHouse, months) → cold (compressed export to object storage, archival) — driven by
  policy config, with retention windows configurable per tenant. Replace hard-delete with
  archive-then-prune. Keep append-only audit tables exempt from pruning.
- **Safety:** Storage-lifecycle only; archive-before-delete; append-only tables never pruned.
- **Progress (2026-07-10):** Phase 1 done — the "archive-then-prune" half of the proposed fix.
  New `SecurityRetentionArchiveService` writes every row about to be pruned to a
  gzip-compressed JSONL file (`storage/app/archives/{table}/{tenant-or-global}/
  {timestamp}.jsonl.gz`) before deleting it; `SecurityRetentionCommand` uses this by default,
  with `--no-archive` to opt back into the old direct-delete behavior and `--archive-dir=` to
  override the base path. Handles `security_events` (no `tenant_id` column at all) separately
  from `security_alerts`/`security_incidents` (`tenant_id` nullable) via an explicit
  `hasTenantColumn` flag, rather than conflating "no column" with "column is null". +10 tests.
  **Not done — needs live infra to build/verify**: the actual warm tier (ClickHouse, months-
  scale searchable) and cold tier (object storage archival + restore); the local gzip archive
  from phase 1 is durable but not itself searchable, so it's a safety net, not yet a real
  "warm" tier.
- **Progress (2026-07-10, phase 2):** Made the phase-1 archive actually searchable — a real,
  bounded local read path, deliberately scoped as a stopgap rather than reattempting the "needs
  live infra" ClickHouse warm tier (still out of reach in this environment; documented as still
  open). New `ArchiveSearchService::search(table, tenantId, from, to, filters, limit)`: lists
  candidate `.jsonl.gz` files under `{archiveDir}/{table}/{tenant-or-*}/`, **skips files whose
  filename timestamp falls outside `[from, to]` without opening them** (the archive's existing
  `{Y-m-d_His_u}.jsonl.gz` filename convention doubles as a coarse time index, so a date-ranged
  search doesn't have to gunzip every file), then linearly scans the remaining files applying an
  exact-match filter map against decoded JSONL rows. Explicitly bounded — `MAX_FILES_SCANNED=200`
  / `MAX_ROWS_SCANNED=200000` / `MAX_RESULTS=500`, `truncated: true` reported whichever limit
  hits first — this is a **local safety-net search, honestly labelled** (`is_local_archive_search:
  true` in every response), not a real indexed warm tier; a genuine ClickHouse warm tier remains
  the actual "not done" item, now more precisely scoped since the safety net itself is provably
  searchable. New `security:archive-search` Artisan command (read-only, no mutation of archive or
  live DB) — `{table} --tenant= --from= --to= --filter=field=value (repeatable) --limit=
  --archive-dir=`, prints JSON results + a `files_scanned=… rows_scanned=… results=… truncated=…`
  summary line, matching `SecurityRetentionCommand`'s existing `--archive-dir` convention/default
  (`storage/app/archives`) so no new path convention was introduced. +11 tests
  (`ArchiveSearchServiceTest`): basic search, exact-match filter hit/miss, tenant scoping
  (single-tenant vs. all-tenants when `tenantId=null`), nonexistent-table-dir empty result,
  `limit`-triggered truncation, date-range file-skip exclusion **and** inclusion (both directions
  verified, not just the exclusion case), plus 2 command-level tests through the real Artisan
  CLI (output-contains assertions on both the matched row and the `--filter` flag). Full
  `php artisan test --parallel --recreate-databases` run confirmed green after adding this
  (see CLAUDE.md's Laravel gate) — no existing file was modified, only additive new
  Service/Command/Test, so zero regression risk to any other suite by construction. **Still
  not done**: the real warm tier (ClickHouse) and cold tier (object storage archival/restore) —
  unchanged from phase 1, both need live infra unavailable in this environment.
- **Progress (2026-07-10, phase 2b):** Closed the CLI-only gap from phase 2 — new
  `ArchiveSearchController` (`GET /archive-search`, RBAC-gated via the existing `soc:search.view`
  permission, reused rather than inventing a new one since it's the same "read-only search over
  historical platform data" concept `SiemSearchController` already uses) and a matching Blade view,
  so an analyst can search the retention archive from the SOC without shell access. **Caught and
  fixed a real tenant-isolation design mistake before it shipped, not after**: the controller was
  first written with a free-text `tenant_id` query parameter a user could type any value into and
  have it passed straight to the search — the exact class of bug this session's own
  `ENT-TENANCY-*` fixes spent multiple passes closing elsewhere in the codebase. Caught during
  design review (not by a failing test) and rewritten to derive tenant scope exclusively from
  `TenantContextAuthority::validateAndResolve()` (the `X-Tenant-ID` header, validated against the
  user's real memberships) — the same boundary every other tenant-scoped SOC controller in this
  codebase enforces — with the free-text field removed from both the controller and the form
  entirely. Deliberately **did not** copy `SiemSearchController`'s `?? 'default'` null-fallback:
  that string is specific to how SiemSearch's OpenSearch-backed index scopes tenants and doesn't
  apply to this archive's directory-per-tenant layout, where `ArchiveSearchService` already
  correctly treats `tenantId=null` as "search across all tenant archive directories" — copying
  the fallback verbatim would have silently returned empty results for the common
  admin/unscoped-legacy-mode case. Controller hard-codes the same default archive path
  `SecurityRetentionCommand`/the phase-2 CLI use (`storage/app/archives`) — no separate config
  concept introduced. Results render as raw JSON blocks per row (archived rows have no fixed
  schema across tables, unlike SiemSearch's alert-shaped rows) alongside the same
  files/rows/results/truncated summary the CLI already prints. +7 new tests
  (`ArchiveSearchControllerTest`): auth-required redirect, RBAC permission check, empty-query
  renders OK, results render for a real archived+matched row, `filters=` query param applied,
  no-match message for a nonexistent table, and the summary counters line — all against the
  controller's real hard-coded default path (fixtures written there and cleaned up per-test,
  since a browser route has no `--archive-dir`-style override the CLI tests could use). Full
  `php artisan test --parallel --recreate-databases` run confirmed green (see CLAUDE.md's
  Laravel gate); `ArchiveSearchServiceTest`/`SiemSearchTest` re-run to confirm zero regression
  since neither was touched. **Still open**: the actual warm/cold tier, unchanged.

## Proposed Task: META-MODULE-RATIONALIZE — Consolidate the self-assessment / evidence-freeze sprawl

- **Priority:** Medium (maintainability / focus)
- **Component:** `app/Services/` — the readiness/certification/maturity/evidence-freeze/soak-sim cluster
- **Finding — verified:** Of 90 services, **~32 are self-referential meta-modules** that
  produce advisory records *about the platform's own readiness* rather than XDR detection/
  response capability: `CommercialReadinessService`, `FinalXdrCertificationService`,
  `EnterprisePilotReadinessMatrixService`, `PilotReadinessService`, `ReleaseCandidateStabilizationService`,
  `TenantStrictModeReadinessService`, `DetectionPromotionReadinessService`, `CodeLevelXdrMaturityService`,
  `CapacityGovernanceService`, `ComplianceGovernanceService`, `ReleaseGovernanceService`,
  `LongRunningOperationalService`, `EnterpriseDeploymentHardeningService`, `DemoPlatformPackagingService`,
  and a **versioned-duplication smell**: `StabilityEvidenceFreezeV2/V3/V4` (+ base) and overlapping
  soak services (`DomainSoakHarnessService`, `DomainSoakSimulationService`, `EndpointSoakPlanService`,
  `RealDomainSoakPlanService`, `Phase1SoakExecutionService`, `Phase1SoakEvidenceFreezeService`,
  `SoakChaosValidationService`). The `RealDomainSoakPlanService` name literally implies an earlier
  one was not real. Each phase also bolts on ~9 tables + 5 hunt domains, inflating the schema and
  the "177 hunt domains" count without adding detection breadth.
- **Why it's off-track:** This is effort spent certifying readiness instead of building the
  capability that readiness would certify (connectors, search, asset context — see the capability
  tasks above). It also dilutes thesis defensibility: reviewers may read "Final XDR Certification"
  as a real accreditation.
- **Proposed fix:** Audit the meta-module set; collapse `StabilityEvidenceFreeze` V2/V3/V4 into a
  single versioned service; merge the overlapping soak services into one; deprecate modules that
  only restate others. No detection/response behavior changes — pure consolidation behind existing
  tests. Redirect the freed surface toward the real-capability tasks.
- **Safety:** Refactor/consolidation only; append-only tables retained (deprecate = stop writing,
  not drop); full PHP suite must stay green.
- **Progress (2026-07-10):** Deliberately did **not** attempt the literal "collapse V2/V3/V4
  into a single versioned service" — investigated first and found the three are structurally
  distinct, not near-duplicates: 12 vs. 22 vs. 16 gates over non-overlapping phase ranges
  (E045-E048 / E049-E054 / E055-...), each with its own table set, command, and controller.
  A real merge risks silently changing gate coverage or losing per-version evidence mid-merge —
  exactly the "quiet regression from consolidation" this task must not introduce, and not
  something to gamble on without the ability to run a live-pipeline verifier in this
  environment. Implemented the safe, additive slice of the same underlying concern instead: a
  **read-only overview facade**, `StabilityFreezeOverviewService::overview()`, that calls each
  version's existing, unchanged `getLatestFreeze()` (V2/V3/V4 confirmed to already share a
  consistent summary shape — `freeze_version`/`phase_range`/`pass_score`/`stability`/
  `frozen_at`/`freeze_approved`) and reports `versions.{v2,v3,v4}` (each null if that version
  has never run) plus `current` — the single most-recently-frozen run across all three by
  `frozen_at`. **Directly addresses the credibility concern the finding itself named**
  ("reviewers may read 'Final XDR Certification' as a real accreditation"): the overview's
  `note` field explicitly states each version covers a different, non-overlapping phase range
  and that `current` is "only the most recently run freeze... not a merged or superseding
  status" — so a reader can no longer mistake v4's status for something that supersedes v2/v3's
  separate, still-valid evidence. New `stability:freeze-overview` Artisan command (read-only,
  writes nothing) renders a per-version table + the most-recent-overall line. Zero behavior
  change to any existing freeze/command/controller — purely a new consumer of already-public
  read methods. +9 tests (`StabilityFreezeOverviewTest`): all-null when nothing ever run,
  always-advisory, single-version reporting, most-recent-wins-across-versions (using
  `$this->travel()` to guarantee distinct `frozen_at` ordering rather than relying on wall-clock
  timing), all-three-independently-reported, an explicit **write-guard test** asserting
  `stability_freeze_runs`' row count is unchanged after calling `overview()` twice (the facade
  must never persist), 2 command-level CLI tests. Full `php artisan test --parallel
  --recreate-databases` run (see CLAUDE.md gate) confirmed green — zero regressions in the
  underlying `StabilityEvidenceFreezeV2/V3/V4Test` suites (92 tests total, unmodified, still
  passing) since none of their code was touched. **Broader audit/deprecation/soak-service-merge
  scope from the original proposed fix remains not done** — this pass deliberately fixed the
  one slice of the sprawl (`StabilityEvidenceFreeze` V2/V3/V4) that had a safe, additive
  solution available without a live-pipeline verifier; the overlapping soak services
  (`DomainSoakHarnessService`/`DomainSoakSimulationService`/`RealDomainSoakPlanService`/etc.)
  and the ~32-service meta-module audit are a separate, larger effort.
- **Progress (2026-07-10, broader audit):** The "Audit the meta-module set" clause done —
  documentation only, `docs/architecture/META_MODULE_AUDIT.md`. Ran a fresh survey rather than
  trusting the finding's stale list: found **33 meta-modules, not ~32** — all 17 the finding
  named are still present, plus **16 more that were never named** (`DomainSoakHarnessService`,
  `DomainSoakSimulationService`, `EndpointSoakPlanService`, `Phase1SoakEvidenceFreezeService`,
  `Phase1SoakExecutionService`, `PilotExecutionService`, `PilotTenantOnboardingService`,
  `RealDomainSoakPlanService`, `RedpandaRecoveryHardeningService`, `RetentionGovernanceService`,
  `RuleEvidenceGovernanceService`, `SecurityHardeningEvidenceFreezeService`,
  `SensorHardeningService`, `SoakChaosValidationService`, `StabilityFreezeOverviewService` [the
  bounded-step work from earlier this pass], `TelemetryScalePilotService`). **The headline
  finding: the sprawl grew, it did not shrink**, between when the finding was written and now.
  Classified all 33 (keep/merge-candidate/naming-risk) — net result: only **2 of 33 are a
  genuine merge-candidate pair** (`PilotReadinessService` + `EnterprisePilotReadinessMatrixService`
  — both literally answer "is the pilot ready," the one true duplicate found), **1 is a lowest-
  production-value candidate for relabeling** (`DemoPlatformPackagingService` — reads like demo/
  defense-prep tooling sitting alongside production governance services), and 2 carry
  **naming risk without a behavior problem** (`CommercialReadinessService`,
  `FinalXdrCertificationService` — already `is_advisory`/`freeze_approved=false`-gated at
  runtime, but the class names alone, read out of context, risk exactly the "reviewers may read
  this as a real accreditation" concern the finding quoted). The rest legitimately answer
  different questions despite sharing near-identical advisory scaffolding — confirming the
  finding's instinct was right about the *shape* (repeated boilerplate) more than the
  *substance* (redundant computation). Documented the soak-service "overlap" is real but not
  actually redundant (7 distinct sub-purposes, verified via docblocks: harness=accumulation,
  simulation=explicitly-dry-run, plan=tiering, execution=the-actual-run, freeze=snapshot-of-run,
  real-plan=multi-phase-rollout, chaos=fault-injection) — recommended, but did **not build**, a
  `SoakOverviewService` mirroring the already-shipped `StabilityFreezeOverviewService` read-only
  pattern rather than a literal merge, generalizing the bounded-step precedent instead of
  re-litigating the same "don't merge without a live verifier" reasoning per-service. Also
  surfaced 4 Controller/Service naming-drift pairs (`EnterpriseDeploymentController` vs.
  `EnterpriseDeploymentHardeningService`, etc.) as a separate low-risk cleanup candidate. Migration
  footprint quantified: 24 files hit the finding's literal search terms, ~36 including
  hardening/pilot/demo-named tables. **Explicitly recommends but does not execute**: the
  `PilotReadinessService` merge, the `SoakOverviewService` build, and any renaming — each is a
  separate, individually-scoped follow-up, not bundled into this documentation pass.
  Documentation-only change — no test run per CLAUDE.md's Test Execution Policy (no executable
  examples/commands/behavior contracts changed).
- **Correction (2026-07-10, later):** Attempted follow-up #1 from the broader audit above (merge
  `PilotReadinessService` into `EnterprisePilotReadinessMatrixService`) and **retracted it after
  investigation, before writing any merge code** — the same discipline already applied to
  `StabilityEvidenceFreeze` V2/V3/V4, this time catching a mistake in this audit's own earlier
  classification rather than in the original finding. `PilotReadinessService` is operational
  execution/tracking (onboarding registration, health checks, success metrics, rollback
  validation, telemetry pressure snapshots, operator sign-off, audit events); `Enterprise
  PilotReadinessMatrixService` is a generic gate/evidence scorecard (`REQUIRED_GATE_IDS` +
  `DIMENSIONS`, evaluated from evidence manually attached via `linkEvidence()` — **not
  hard-coded to `PilotReadinessService` at all**). Grep confirms **zero references between the
  two services** — no shared models, no calls either direction; the gate IDs conceptually
  overlap with what `PilotReadinessService` tracks (e.g. `rollback_readiness` ↔
  `validateRollback()`), but the matrix service is architecturally an evidence-aggregator over
  *any* source, the same role `StabilityEvidenceFreeze` plays relative to the services *it*
  aggregates from — an aggregator is not a duplicate of what it aggregates. Updated
  `docs/architecture/META_MODULE_AUDIT.md` in place: reclassified both services from
  "Merge-candidate" to "Keep," added a new §3a documenting the correction and the generalized
  lesson (name-similarity alone is exactly the "distinct nouns, same verb" mistake this codebase
  already learned to avoid), updated the "Net recommendation" count from "2 of 33" to "0 of 33"
  merge-candidates, and struck the retracted step from the "Concrete next steps" list. **No
  merge attempted, no facade needed either** — unlike V2/V3/V4, these two don't cover
  sequential/overlapping ranges of the same evidence, so there's no "which one is current"
  ambiguity for an overview facade to resolve. Documentation-only correction, no test run per
  CLAUDE.md policy.
- **Progress (2026-07-10, follow-up #2):** Built follow-up #2 from the broader audit —
  `SoakOverviewService`, mirroring `StabilityFreezeOverviewService`'s read-only pattern, but
  **deliberately not the same shape**: the freeze services all share an identical
  `getLatestFreeze()` return structure (sequential phase ranges of the same evidence), while the
  7 soak services were already confirmed structurally distinct in the audit — no uniform
  "latest run" interface exists across them, so forcing one would have been artificial.
  `SoakOverviewService::overview()` instead calls each service's own most relevant *existing*
  read method as-is: `DomainSoakHarnessService::getSummary()` (zero-arg aggregate — already an
  overview, not a single run), `DomainSoakSimulationService::getSimulations()->first()` (already
  ordered by `simulated_at` DESC), `EndpointSoakPlanService::getLatestPlan()`,
  `Phase1SoakExecutionService::getLatestRun()`, `Phase1SoakEvidenceFreezeService::getLatestFreeze()`,
  `RealDomainSoakPlanService::getLatestPlan()`, `SoakChaosValidationService::dashboardStats()`
  (also a zero-arg aggregate). Each entry tagged with its own `kind` string (e.g.
  `evidence_accumulation_summary`, `dry_run_simulation_latest`, `real_run_latest`,
  `chaos_fault_injection_dashboard`) rather than presenting a false uniform "status" — the
  `note` field explicitly states "this is a read-only survey of each service's own current
  status, not a single merged soak status," matching the same anti-conflation discipline
  `StabilityFreezeOverviewService`'s `note` already established. New `soak:overview` Artisan
  command (read-only, writes nothing). +14 tests (`SoakOverviewTest`): all 7 keys present,
  always-advisory, note wording check, harness/chaos aggregates present-but-zero when nothing
  has run (distinguishing "zero count" from "null" — the 5 other services correctly report
  `null` data when never run, since they have no meaningful zero-state), then a seeded-real-run
  test **for each of the 5 `getLatest*`-style services** (calling each service's own real
  producing method — `generatePlan(false)`/`buildRun(false)`/`freeze(false)`/`buildPlan(false)`/
  `simulate('endpoint', false)` — not a mock) confirming the overview picks up real data end to
  end, an explicit write-guard test (row count unchanged after calling `overview()` twice), and
  2 command-level CLI tests. All 14 passed on the first run — every one of the 7 services'
  actual method signatures matched what the audit's earlier survey had found. Full
  `php artisan test --parallel --recreate-databases` run confirmed green (see CLAUDE.md gate).
  **Item 3 of the broader audit's "Concrete next steps" (redirect new capability work toward
  connectors/search/asset-context rather than a 34th meta-module) is a standing principle, not
  a discrete task — this session continues to follow it, not add to the sprawl.**

## Proposed Task: SIM-LAYER-REALITY-GATE (Track B) — Back the key HA/scale/chaos/soak simulators with real infra

> **Track A (labelling) completed 2026-07-06** — see REVIEW_COMPLETED.md. All 35 validation-run
> tables across `EnterpriseScaleHaService`, `TelemetryScalePilotService`, `SoakChaosValidationService`,
> `PilotExecutionService` now carry `is_simulated`/`evidence_basis`, defaulting `true`/`'computed'`
> since none of these validators currently exercise real distributed infrastructure. This section now
> tracks only the remaining Track B scope.

- **Priority:** High (credibility / enterprise validity)
- **Component:** `EnterpriseScaleHaService`, `TelemetryScalePilotService`, `SoakChaosValidationService`,
  `PilotExecutionService`, `docker-compose.ha.yml`
- **Finding — verified:** Redpanda is single-node (GAP-004), so an `EnterpriseScaleHaService`
  "HA_PASS" is a computed record, not a failover proof. `docs/thesis/LIMITATIONS_FUTURE_WORK.md`
  states it plainly: *"HA governance, multi-tenant isolation, and cluster topology are implemented
  at the advisory/simulation layer, not tested under real distributed load."* Now that every record
  is explicitly labelled `is_simulated=true`/`evidence_basis='computed'` (Track A), a real PASS can
  no longer be confused with a computed one — but no validator produces a real PASS yet.
- **Why enterprise-relevant:** "Full enterprise XDR" claims must eventually be backed by real
  evidence, not just honestly-labelled simulated evidence.
- **Proposed fix:** Wire `EnterpriseScaleHaService`/soak validators to the real `docker-compose.ha.yml`
  multi-node path (ties into GAP-004 / HA-DRILL-01) so at least HA-failover and soak can produce
  *measured* evidence (`evidence_basis='measured'`) before any production-readiness claim.
- **Safety:** Real validation harness only; advisory-only records preserved; no autonomous action;
  append-only tables untouched.

---

# Claude QC Deep Dive (2026-07-06)

Deeper findings from Claude (source: `REVIEW_ALL.md` — Review Batch 19, CLAUDE-QC-DEEP-DIVE). Verified
non-duplicate against REVIEW_ALL / REVIEW_REJECTED / REVIEW_COMPLETED. None crosses a CLAUDE.md Forbidden
Change. Batch 18/19 note: CONSUMER-GROUP-EPHEMERAL and NORM-ASYNC-COMMIT-LOSS both completed — see
REVIEW_COMPLETED.md.


---

# Capability / Power Expansion (2026-07-06)

Genuinely-absent enterprise XDR capabilities that increase detection/response/analyst power.
Verified non-duplicate (grep: no Sigma import, no STIX/TAXII client, zero MSSP/honeytoken code,
MITRE only tagged not coverage-mapped). **Every item stays inside CLAUDE.md Forbidden Changes** —
all are advisory / shadow / approval-gated, none add autonomous containment, offensive endpoint
capability, or bypass a soak gate. Each still needs Claude validation before implementation.

## Proposed Task: CAP-DETECT-AS-CODE-SIGMA — Sigma → detection-registry compiler
Import community/vendor Sigma YAML rules into `registry.v1.json` via a field-mapping compiler
(Sigma logsource/fields → the normalized telemetry schema). Turns a hand-authored 133-rule set into
one that can absorb thousands of open rules. **Safety:** imported rules land as `shadow`, subject to
the same domain-specific 6h soak gate before any `staged_active` promotion — the hard gate is untouched.
- **Progress (2026-07-11):** Done, with an honest scope correction discovered during research.
  New `App\Services\SigmaImportService`: `parse()` (Symfony YAML, already a transitive Laravel
  dependency, no new composer package) + `compile()` producing a full `registry.v1.json` entry —
  domain inferred from Sigma `logsource.product/category/service` (lookup table, e.g.
  `product: okta` → `identity`, `product: aws` → `cloud`, `category: process_creation` → `endpoint`),
  `severity` from `level` (`informational` → platform's `info`), `mitre_attack` from `tags:
  [attack.txxxx]` (prefix-stripped, uppercased), `shadow_only`/`output_topic` auto-set correctly
  for protected domains (`endpoint`/`threat-intel`/`network`), `confidence` fixed at the
  validator's 0.65 floor, `rule_id` slugified from `title` with dedup-on-collision. **Always**
  emits `status: "shadow"` — never `staged_active` (that needs a real domain-specific 6h soak
  PASS, which an import can never provide). New `detection:import-sigma {files*} {--dry-run}
  {--registry=}` artisan command wraps it (the `--registry=` override exists purely for test
  isolation — never touches the real file during tests).
  **Scope correction (important):** research before implementing found that `registry.v1.json`
  is a metadata/governance catalog everywhere in this codebase — every one of the existing 133
  rules has its actual match logic hand-coded in Go (`internal/shadowrules`) or Python
  (`scripts/xdr_correlation_detector.py`), never driven by the registry JSON itself, which has
  no field-condition/expression language at all. So this compiler produces a **catalog entry**
  (MITRE mapping, severity, suppression scaffolding, field-mapping hints) with the original Sigma
  `detection:` block preserved verbatim in a new `sigma_source` field for later hand-implementation
  — it does not, and structurally cannot without inventing a whole new generic rule-expression
  engine (a much bigger, separate task), auto-generate executable shadow-detection logic. This
  is consistent with how every other rule in the registry already works, not a shortfall unique
  to Sigma import — but it does mean "ingest thousands of open rules" overstates what happens
  today: it bootstraps the *catalog/governance* side of onboarding a ruleset quickly, not live
  detection coverage.
  Verified end-to-end against 2 real fixture rules (`tests/fixtures/sigma/`, a Windows/
  `process_creation` PowerShell-encoded-command rule and an Okta MFA-bypass rule) — compiled,
  written to a full copy of the real 133-rule registry, and passed
  `xdr_rule_registry_validate.py`'s all-21-checks gate at 135 rules (then reverted — the demo
  import was not left in the production registry; see REVIEW_COMPLETED.md). +15 tests (11 unit
  `SigmaImportServiceTest`, 4 feature `DetectionImportSigmaCommandTest` — including one that
  shells out to the real Python validator against an isolated temp registry copy, so the
  validator's own exit code is the source of truth, not just this compiler's self-assessment).


## Proposed Task: CAP-MSSP-TENANCY — Parent/child tenant hierarchy + MSSP analyst roles
Add a tenant hierarchy (an MSSP parent overseeing many customer tenants) with cross-tenant **read-only**
rollup dashboards and MSSP-scoped RBAC roles. **Safety:** depends on ENT-TENANCY-NO-DB-ENFORCEMENT (RLS)
so cross-tenant visibility is DB-enforced; rollups are advisory/read-only, no autonomous cross-tenant action.

## Proposed Task: CAP-DECEPTION-HONEYTOKEN — Honeytoken/canary detection (advisory)
Seed honeytokens (fake credentials, files, URLs, DNS names) and raise a high-signal advisory finding when
one is touched in telemetry. **Safety:** purely **detective** — no offensive deployment onto third-party
systems, no active response; findings follow the existing advisory/shadow path.


## Proposed Task: CAP-DETECT-BACKTEST — Historical "would-have-fired" backtest for candidate rules
Extend the replay layer: run a candidate detection against N-days of retained normalized telemetry and
report an advisory would-have-fired count + sample matches, so a rule's quality is known **before** a soak.
**Safety:** replay-safe, advisory-only, writes to `detection_replay_results` (append-only) — never emits
active alerts or incidents.
