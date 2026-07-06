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
| **CODE-STRUCT-DECOMPOSE** (correlation-worker rule engine + normalizer-worker normalizer family both decomposed; Pandaproxy transport + alert-writer-service remain) | [Structure/Maintainability] `correlation-worker/main.go`: 1165 lines (was 2950, 60% reduction) — shadow-rule engine in `internal/shadowrules`/`internal/ioc`. `normalizer-worker/main.go`: **732 lines (was 1223, a 40% reduction)** — all 12 per-telemetry-type normalizer functions (`Endpoint`/`Sysmon`/`PowerShell`/`WindowsSecurityEvent`/`Dns`/`Proxy`/`Firewall`/`IdentityProvider`/`SaasAudit`/`TicketSync`/`NotificationEvent` + the `Event` dispatcher), previously **zero unit test coverage in isolation** (only indirectly exercised via 4 Pandaproxy-mock integration tests hitting the default envelope path), now live in `internal/normalize` with 14 new direct unit tests. Dockerfile fixed to `COPY internal ./internal` (previously only copied `main.go` — would have broken the Docker build silently, caught before it shipped). Remaining: Pandaproxy consume/produce transport in both workers (`internal/kafka`, tightly coupled to Worker/HTTP state — high-risk, left in place matching correlation-worker's precedent); `alert-writer-service` (1277 lines, Python, untouched) | `services/correlation-worker/main.go`, `services/normalizer-worker/main.go`, `services/normalizer-worker/internal/normalize/`, `services/alert-writer-service/main.py` | Medium | Proposed (reduced) |
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
| **CAP-TI-STIX-TAXII** | [Power — real TI platform] Current IOC path is a static lookup; no STIX 2.1 / TAXII 2.1 inbound client, no IOC lifecycle (confidence decay, expiry, source provenance). Add a bundled/offline-first TAXII poller + IOC lifecycle so threat-intel is first-class, not a flat list. Advisory enrichment only | new `services/ti-connector` or `app/Services/ThreatIntel*`, `ioc_*` tables | High | Proposed |
| **CAP-DETECT-BACKTEST** | [Power — detection engineering] Extend the existing replay layer into a historical backtest: run a candidate/new detection against N-days of retained normalized telemetry and report an advisory "would-have-fired" count + sample matches, before committing to a soak. Replay-safe, advisory-only, no active alerts written | `app/Http/Controllers/Detection/DetectionRuleController.php`, `detection_replay_results`, replay engine | High | Proposed |

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

## Proposed Task: CAP-TI-STIX-TAXII — First-class threat-intel platform (STIX 2.1 / TAXII 2.1)
Replace the flat IOC lookup with a real TI layer: a TAXII 2.1 poller (bundled/offline-first feeds by
default), STIX 2.1 object parsing, and IOC lifecycle (source provenance, confidence decay, expiry).
**Safety:** enrichment/advisory only — feeds detection scoring, never triggers response.

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
