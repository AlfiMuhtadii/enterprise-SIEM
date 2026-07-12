# Pending Hardening & Cleanup Tasks (Backlog)

This file tracks all pending security hardening, refactoring, and documentation alignment tasks.
It is synchronized with GitHub Issues. Developers/writing agents (e.g., Claude) should resolve these tasks.

---

## Open Backlog Tasks

| Task ID | Title | File / Component | Priority | Status |
|---|---|---|---|---|
| **ENT-SEC-NO-TLS-INTERNAL** (phases 1-7 done — all 10 custom services have app-layer mTLS scaffolding, off by default; Pandaproxy/OpenSearch/docker-compose-network remain, genuinely blocked by no live Docker daemon in this environment) | [Enterprise BLOCKER] Every custom service (3 core Go pipeline services, 4 Go log-connectors, 3 Python FastAPI services) now has the `internal/mtls` mechanism (Go) or a uvicorn `--ssl-*` entrypoint wrapper (Python), gated behind `XDR_INTERNAL_MTLS_ENABLED` (default `false` — zero behavior change until an operator opts in). Postgres `sslmode` made configurable (`DB_SSLMODE`, default unchanged `'prefer'`) — full Postgres mTLS (client cert) not wired, needs a live server to verify. Verified with real end-to-end handshake smoke tests (Go services) and real script execution against generated certs (Python services); zero regressions across existing suites. Still missing — genuinely blocked, not just undone: Pandaproxy (Redpanda REST proxy) TLS, OpenSearch TLS, Postgres full mTLS, and the docker-compose network wiring to actually run mTLS between live containers — all four need a running Docker daemon (`docker info` fails: "failed to connect to the docker API") to configure and verify, which this environment does not have | `services/*/internal/mtls/`, `services/{alert-writer-service,incident-builder-service,ai-rag-service}/docker-entrypoint.sh`, `scripts/xdr_generate_internal_mtls_certs.py`, `config/database.php`, `app/Services/InternalAuthService.php` | High | Proposed (reduced) |
| **ENT-TENANCY-NO-DB-ENFORCEMENT** | [Enterprise BLOCKER] Isolation is app-layer `where('tenant_id')` only (ASSET-TENANT-OVERWRITE proves it leaks); no DB RLS. Supersedes deferred TENANT-ENFORCE-RLS — mandatory for multi-tenant SaaS | `app/Services/TenantBoundaryService.php`, Postgres RLS | High | Proposed (re-open TENANT-ENFORCE-RLS) |
| **ENT-REL-SIMULATED-HA** | [Enterprise BLOCKER] HA/scale/DR "PASS" is computed, not measured on real cluster (SIM-LAYER Track B + HA-DRILL-01). "Too heavy for laptop" invalid at enterprise bar — run on real staging before any availability claim | `app/Services/EnterpriseScaleHaService.php` et al., `docker-compose.ha.yml` | High | Proposed (re-open Track B + HA-DRILL-01) |
| **ENT-SDLC-NO-SUPPLYCHAIN** (base-image digest pinning + SBOM generation + dependency vuln scanning done; image scan/sign remain) | [Enterprise SDLC] Python `requirements.txt` already pin exact versions and Go services have zero external deps — already fine. Dockerfiles now pinned to resolved digests. `scripts/xdr_generate_sbom.py` now generates a CycloneDX 1.5 SBOM per service (`docs/security/sbom/*.cyclonedx.json`) directly from requirements.txt/go.mod/digest-pinned Dockerfiles — no syft binary needed. `scripts/xdr_dependency_vuln_scan.py` now scans real SOURCE dependencies (not container images): `govulncheck` call-graph reachability analysis for all 8 Go services against vuln.go.dev, `pip-audit` (invoked via `python -m pip_audit` to sidestep a PATH gap) against all 3 Python services' requirements.txt — advisory evidence report (`reports/xdr_dependency_vuln_scan.json`), never a hard gate, matching the platform's no-autonomous-action posture. Current real result: 5 distinct Go stdlib CVEs (toolchain-tied, not third-party — this codebase has zero external Go deps), all 3 Python services clean. Still missing: container IMAGE vuln scan gate (trivy) and signed builds (cosign) — both need a running Docker daemon (`docker info` fails: "failed to connect to the docker API"), which this environment does not have | `services/*/Dockerfile`, `scripts/xdr_generate_sbom.py`, `scripts/xdr_dependency_vuln_scan.py`, CI | Medium | Proposed (reduced) |
| **IDENTITY-SSO-MFA** (TOTP MFA + mandatory enforcement + OIDC SSO federation + SAML SSO federation all done) | [Enterprise BLOCKER — re-ranked] Per-user opt-in TOTP now implemented (`TotpService`, RFC 6238, dependency-free — verified against the RFC's own published test vector, not just self-consistency). Login gates on a 6-digit code when a user has enabled it; existing password-only login is unaffected for everyone else. Mandatory MFA enforcement now wired (`EnsureMfaVerified`/`mfa.required` middleware, gated by `SOC_MFA_ENFORCEMENT_ENABLED`, default `false`) on response-plan/active-response/data-erasure approve routes. OIDC authorization-code SSO federation now implemented (`OidcSsoService`/`OidcSsoController`, gated by `SOC_OIDC_SSO_ENABLED`, default `false`) — real RS256 ID-token signature verification via `firebase/php-jwt` + JWKS. SAML 2.0 SP-initiated SSO federation now also implemented (`SamlSsoService`/`SamlSsoController`, gated by `SOC_SAML_SSO_ENABLED`, default `false`) — real XML-DSig assertion-signature verification + XSD schema validation via `onelogin/php-saml` (the other place, alongside OIDC's `firebase/php-jwt`, this codebase's usual "dependency-free protocol implementation" convention was deliberately not followed — hand-rolling XML signature verification is a well-known vulnerability source, XML signature wrapping specifically). Both federation paths never auto-provision accounts — only an existing user matched by a verified/signed identity claim can sign in; per-user TOTP is still enforced after SSO login either way. Verified against real local mock IdPs (genuine ephemeral keypairs, real signed/verified tokens/assertions) since no live external IdP exists in this environment. Still missing: end-to-end verification against a real corporate IdP (Okta/Azure AD/ADFS) for either protocol — needs a real external IdP this environment doesn't have; `/sso/saml/metadata` is the concrete handoff point for that future pass | `app/Services/TotpService.php`, `app/Services/OidcSsoService.php`, `app/Services/SamlSsoService.php`, `app/Http/Controllers/Auth/MfaController.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Controllers/Auth/OidcSsoController.php`, `app/Http/Controllers/Auth/SamlSsoController.php`, `app/Http/Middleware/EnsureMfaVerified.php`, `config/oidc.php`, `config/saml.php`, `routes/auth.php`, `routes/web.php` | High | Proposed (reduced) |
| **OBS-OTEL-TRACING** (phases 1-5 done — W3C traceparent across all 6 hops + OTLP/HTTP span export wired across the whole platform: 3 Go pipeline services, 2 Python services, Laravel SOC control plane) | All application-layer phases complete — see `REVIEW_COMPLETED.md` for full per-phase detail. Only a real live OTel collector to actually visualize the emitted spans (Tempo/Jaeger, compose `observability` profile) remains, blocked on no live Docker daemon in this environment. | `services/{ingestion-gateway,normalizer-worker,correlation-worker}/main.go`, `services/{alert-writer-service,incident-builder-service}/*.py`, `app/Http/Middleware/SecurityRequestLogger.php`, `app/Services/OtlpExportService.php` | Medium | Proposed (reduced) |
| **CODE-STRUCT-DECOMPOSE** (correlation-worker, normalizer-worker, alert-writer-service, incident-builder-service, ThreatHuntingService, ReportExportService, UEBABaselineService, EntityRiskScoringService decomposed; Pandaproxy/Kafka transport intentionally remains) | See detail section below | see detail section | Medium | Proposed (reduced) |
| **DATA-TIERING** (phases 1/2/2b done — archive-then-prune, searchable local archive, RBAC-gated UI; warm/cold infra tiers remain) | See detail section below | `app/Services/SecurityRetentionArchiveService.php`, `app/Services/ArchiveSearchService.php` | Medium | Proposed (reduced) |
| **SIM-LAYER-REALITY-GATE** (Track B only — Track A done) | [Dummy → must be real] Track A (labelling) done: all 35 HA/scale/chaos/soak/pilot validation-run tables now carry `is_simulated`/`evidence_basis`. Remaining: Track B — back the key validators (HA failover, scale, soak) against a real multi-node harness (`docker-compose.ha.yml`) so they produce *measured*, not just *computed*, evidence | `app/Services/EnterpriseScaleHaService.php`, `TelemetryScalePilotService.php`, `SoakChaosValidationService.php`, `PilotExecutionService.php`, `docker-compose.ha.yml` | High | Proposed (reduced) |
| **ARCH-KAFKA-NATIVE + PERF-REST-POLL + PERF-REST-REBALANCE** (bundled — same transport rewrite; code done, feature-flagged off by default, live-pipeline verification remains) | [Enterprise throughput/reliability] New `internal/kafkanative` package (franz-go — first external Go dependency any of these 3 services has taken on) in `ingestion-gateway`/`normalizer-worker`/`correlation-worker`, opt-in via `XDR_KAFKA_TRANSPORT=native` (default `pandaproxy`, zero behavior change). Root-caused PERF-REST-REBALANCE: the REST consumer "instance ID" was regenerated from a timestamp on every reconnect, not just process restart — native Kafka's connection+heartbeat model has no "instance" concept, structurally eliminating the failure mode. PERF-REST-POLL resolved as a side effect (native long-poll replaces the REST round-trip). `go build`/`go vet`/`go test ./...` clean, zero regression; new tests run against `franz-go/pkg/kfake` (a real in-process fake Kafka broker, genuine wire protocol, not mocked HTTP) proving real produce/consume/commit/poison-isolation behavior. `govulncheck` clean (zero new third-party vulnerabilities). Also fixed a pre-existing bug found along the way: `ingestion-gateway/Dockerfile` was missing `COPY internal ./internal` entirely — any real `docker build` of it was already broken. GATE (unmet, genuinely blocked): CLAUDE.md's live-pipeline verifier (`scripts/demo_causal_verify.py` + `scripts/validate_live_xdr_pipeline.py` against a real Redpanda broker) needs a live Docker daemon this environment doesn't have | `services/{ingestion-gateway,normalizer-worker,correlation-worker}/main.go`, `services/{ingestion-gateway,normalizer-worker,correlation-worker}/internal/kafkanative/`, `docker-compose.yml` | High | Proposed (reduced) |
| **ARCH-DB-SPLIT** (write path + soak tooling done 2026-07-12; live-verified against real Postgres+ClickHouse same day once Docker became available — real bug found+fixed, real throughput/contention evidence captured; read path remains) | [Enterprise scale — promoted 2026-07-06] Both `telemetry_events` write paths (Python batch/stream ingesters + `AgentIngestionController::telemetry()`) now route to a new ClickHouse `telemetry_events` table (with `tenant_id` from day one) behind `XDR_TELEMETRY_WRITE_TARGET` (default `postgres`, zero behavior change). `scripts/load_test_soc.py` gained a real concurrent write-throughput soak generator (`--ingest-target`/`--ingest-concurrency`). Once a Docker daemon became reachable mid-session, ran this for real: found and fixed a genuine bug (ClickHouse's default `DateTime64` parser rejects plain ISO-8601 timestamps — `date_time_input_format=best_effort` added to the HTTP query URL in both `ClickHouseClient.query()` and `ClickHouseTelemetryWriter::insert()`), then captured real measured evidence — ClickHouse ~29-54% higher write throughput than Postgres across concurrency levels, and (the actual empirical claim this item exists to test) a real dashboard-style aggregation query against Postgres slowed 3.8x on average (9.4x at p95) while a concurrent 16-worker telemetry write soak was running. Deliberately scoped to the write side only — 7+ read paths (dashboards, 2 correlation detectors feeding real alerts, hunt, forensics, backtest) remain a separate, larger, correctness-sensitive follow-on. GATE now partially met: the OLTP-contention justification is empirically confirmed, but the item isn't done until the read-path migration itself exists — the contention evidence demonstrates the problem, not a completed fix | `scripts/ingest_telemetry_events.py`, `scripts/telemetry_stream_worker.py`, `scripts/xdr_infra_clients.py`, `scripts/load_test_soc.py`, `app/Services/ClickHouseTelemetryWriter.php`, `app/Http/Controllers/AgentIngestionController.php` | High | Proposed (reduced) |
| **ARCH-DISCOVERY** | [Enterprise infra — promoted 2026-07-06] Static hostnames only; multi-node needs DNS/service discovery + internal LB. Belongs with a real multi-node deploy (ties ENT-REL-SIMULATED-HA / HA-DRILL-01) | `docker-compose*.yml`, deploy manifests | Medium | Proposed (staged — infra) |
| **AI-KB-SEMANTIC** | [Enterprise AI — promoted 2026-07-06] Qdrant + cosine ranking path exists (`SocKnowledgeRetriever::retrieveQdrant`); only a live transformer embedding model is missing (currently offline pseudo-embeddings). Needs a bundled/served embedding model — conflicts with offline-first default, so gate behind a flag | `app/Support/SocKnowledgeRetriever.php`, embedding service | Medium | Proposed (staged — needs ML infra) |
| **SOAR-TENANCY-GAP** | [TENANCY] Implement Tenant Isolation for SOAR Playbooks, Execution Plans, and Approvals. | SOAR controller, service, and database migrations | High | Proposed |
| **HUNT-TENANCY-GAP** | [TENANCY] Scope Threat Hunting queries, results, and host/process pivots by Tenant. | `app/Services/ThreatHuntingService.php`, `ThreatHuntController` | High | Proposed |
 
> **This file tracks only pending/open tasks.** Completed tasks live in `REVIEW_COMPLETED.md`; rejected/deferred in `REVIEW_REJECTED.md`.
>
> **Classified out (2026-06-29):** `EDR-EXEC-02` and `AI-CONF-BANDS` → REJECTED (forbidden: automated active containment). `TENANT-ENFORCE-RLS` → DEFERRED (gated by RLS_DECISION_RECORD + GAP-002/003). See REVIEW_REJECTED.md.
>
> **Promoted from footnote → actionable rows (2026-07-06):** the BATCH-DEFER-2026-07-01 cluster is now open task rows above (`ARCH-KAFKA-NATIVE`, `PERF-REST-POLL`, `PERF-REST-REBALANCE`, `PERF-GO-LIMITER`, `PERF-GO-OVERCONCURRENT`, `ARCH-DB-SPLIT`, `ARCH-DISCOVERY`, `AI-KB-SEMANTIC`; `ARCH-MTLS-SEC` is covered by `ENT-SEC-NO-TLS-INTERNAL`). Each carries its CLAUDE.md validation gate (live-pipeline verifier for hot-path/transport, Go race tests, or real infra) and must not be batched with unrelated work. `ARCH-KAFKA-NATIVE`/`PERF-REST-POLL`/`PERF-REST-REBALANCE` moved from staged to reduced 2026-07-12 (code done, feature-flagged off, kfake-tested — see the bundled row above and `REVIEW_COMPLETED.md`); the rest remain **staged**. `AI-KB-FEED-INGEST` was completed 2026-07-10 (re-scoped to a bundled offline dataset import) — see `REVIEW_COMPLETED.md`.

---

## Proposed Task: IDENTITY-SSO-MFA — Enterprise SSO (SAML/OIDC) + MFA for analyst auth

- **Priority:** High
- **Component:** `app/Services/TotpService.php`, `app/Services/OidcSsoService.php`, `app/Services/SamlSsoService.php`, `app/Http/Controllers/Auth/MfaController.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Controllers/Auth/OidcSsoController.php`, `app/Http/Controllers/Auth/SamlSsoController.php`, `app/Http/Middleware/EnsureMfaVerified.php`, `config/oidc.php`, `config/saml.php`
- **Status:** TOTP MFA + mandatory enforcement + OIDC SSO federation + SAML SSO federation all done (see `REVIEW_COMPLETED.md` for full detail — `TotpService`, RFC 6238, dependency-free, verified against the RFC's published test vector; per-user opt-in 2-step login; `EnsureMfaVerified`/`mfa.required` middleware on response-plan/active-response/erasure approve routes, gated by `SOC_MFA_ENFORCEMENT_ENABLED` default `false`; `OidcSsoService`/`OidcSsoController`, authorization-code flow + real JWKS-based RS256 ID-token verification via `firebase/php-jwt`, gated by `SOC_OIDC_SSO_ENABLED` default `false`; `SamlSsoService`/`SamlSsoController`, SP-initiated SAML 2.0 + real XML-DSig/XSD verification via `onelogin/php-saml`, gated by `SOC_SAML_SSO_ENABLED` default `false`; both federation paths never auto-provision accounts and are verified against real local mock IdPs; 49 new tests total across all phases).
- **Remaining scope & Local Verification Workaround:** 
  * The remaining work involves end-to-end verification against a real corporate IdP (Okta/Azure AD/ADFS) for both OIDC and SAML.
  * **Local Workaround:** Developers can build and run lightweight local mock IdPs using Node.js (e.g., `oidc-provider` library for OIDC, or the `saml-idp` npm package for SP-initiated SAML 2.0) running on custom host ports (e.g., `http://localhost:9000`). By updating the local `.env` and `config/oidc.php` or `config/saml.php` to point to these local host endpoints, developers can perform live OIDC/SAML logins and signatures checks locally.

## Proposed Task: OBS-OTEL-TRACING — Standards-based distributed tracing across polyglot services

- **Priority:** Medium
- **Component:** `services/ingestion-gateway`, `services/normalizer-worker`,
  `services/correlation-worker`, `services/alert-writer-service`,
  `services/incident-builder-service`, `app/Services/TraceparentService.php`
- **Status:** Phases 1-3 done (see `REVIEW_COMPLETED.md`) — W3C Trace Context `traceparent`
  generation/parsing/propagation implemented dependency-free across all 6 hops: Go
  (`internal/traceparent`, 3 services), Python (`traceparent.py`, 2 services), PHP
  (`TraceparentService`, wired into `SecurityRequestLogger`). 84 tests total across the 3
  phases, all green. Phase 4 done (see `REVIEW_COMPLETED.md`) — dependency-free OTLP/HTTP+JSON
  span exporter (`internal/otlpexport`) wired into all 3 core Go pipeline services
  (ingestion-gateway, normalizer-worker, correlation-worker), disabled by default
  (`XDR_OTEL_EXPORTER_ENDPOINT` empty), verified end-to-end against a local mock collector
  (no live OTel collector available in this environment). Phase 5 done (see
  `REVIEW_COMPLETED.md`) — the same OTLP/HTTP+JSON wire format ported to Python
  (`otlp_export.py`, duplicated into both `alert-writer-service` and
  `incident-builder-service`) and PHP (`OtlpExportService`, wired into
  `SecurityRequestLogger::terminate()` using Laravel's terminable-middleware mechanism so the
  export call runs after the response is already sent to the client). All 6 hops of the
  platform now emit real OTLP spans, not just standards-shaped propagation context.
- **Remaining scope:** Only a real live OTel collector (compose `observability` profile) to
  actually stitch/visualize the emitted spans (Tempo/Jaeger) remains — this environment can
  only verify each exporter's wire format and HTTP behavior against a mock server, not a real
  collector's ingestion/UI, since there is no running Docker daemon here.

## Proposed Task: CODE-STRUCT-DECOMPOSE — Decompose monolithic single-file services

- **Priority:** Medium
- **Status:** Done for the services worked on this session (see `REVIEW_COMPLETED.md` for full
  per-seam detail):
  - `correlation-worker/main.go`: 2950→1165 lines (60% reduction) — entire endpoint/network
    shadow-rule engine extracted to `internal/shadowrules`/`internal/ioc`.
  - `normalizer-worker/main.go`: decomposed into `internal/normalize` (earlier pass).
  - `alert-writer-service/main.py`: `fingerprint()`/`alert_id()` extracted to `alert_identity.py`.
  - `incident-builder-service/main.py`: 708→645 lines — alert-to-incident grouping extracted
    to `incident_aggregation.py`.
  - `app/Services/ThreatHuntingService.php`: 2524→1145 lines (55% reduction) — the 1150-line
    `DOMAIN_FIELDS` security allowlist extracted to `ThreatHuntQueryAllowlist`.
  - `app/Services/ReportExportService.php`: 994→438 lines (56% reduction) — rendering/templating
    logic extracted to `ReportRenderer`.
  - `app/Services/UEBABaselineService.php`: 806→730 lines — pure statistical math
    (robustZScore/percentileRank/computeMAD/computeMedian/computeStats/
    percentileRankFromBaseline/computeConfidence) extracted to `UEBAStatistics`.
  - `app/Services/EntityRiskScoringService.php`: 989→886 lines — WEIGHTS/LEVEL_THRESHOLDS/
    MAX_SCORE + scoreToLevel/aggregateScore/makeFactor extracted to `EntityRiskFactorScoring`.
- **Remaining scope:** Pandaproxy/Kafka transport in all 3 Go/Python pipeline services is
  intentionally left untouched (tightly coupled to Worker/connection state — high-risk).
  The `collect*Factors()` methods in `EntityRiskScoringService` (still 886 lines) are all
  DB-coupled and not a similar pure-extraction opportunity.

## Proposed Task: DATA-TIERING — Tiered long-term searchable log storage / retention lifecycle

- **Priority:** Medium
- **Status:** Phases 1/2/2b done (see `REVIEW_COMPLETED.md` for full detail):
  - Phase 1: `SecurityRetentionArchiveService` — archive-then-prune (gzip JSONL) instead of
    hard delete, replacing the old direct-delete behavior by default.
  - Phase 2: `ArchiveSearchService` — bounded local search over the archive
    (`security:archive-search` Artisan command), honestly labelled as a local safety-net
    search, not a real indexed warm tier.
  - Phase 2b: `ArchiveSearchController` — RBAC-gated (`soc:search.view`) UI so an analyst can
    search the archive without shell access; tenant scope derived from
    `TenantContextAuthority`, not a free-text field.
- **Remaining scope:** The real warm tier (ClickHouse, months-scale searchable) and cold tier
  (object storage archival/restore) — both need live infra unavailable in this environment;
  the local gzip archive is durable but not a substitute for either.

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
- **Proposed fix & Local Verification Workaround:** 
  * Wire `EnterpriseScaleHaService`/soak validators to the real `docker-compose.ha.yml` multi-node path (ties into GAP-004 / HA-DRILL-01) so at least HA-failover and soak can produce *measured* evidence (`evidence_basis='measured'`).
  * **Local Workaround:** If local machine memory is constrained, developers can run Redpanda in low-resource mode by limiting each broker's memory cache and reserving zero memory:
    `redpanda start --memory 512M --reserve-memory 0M --default-log-bytes 100M`
    This allows a 3-broker HA cluster to run in under 1.5GB of RAM locally. Alternatively, the application logic can be tested using mock consumer/connection pools that inject artificial partition failures and rebalances.
- **Safety:** Real validation harness only; advisory-only records preserved; no autonomous action;
  append-only tables untouched.

## Proposed Task: SOAR-TENANCY-GAP — Implement Tenant Isolation for SOAR Playbooks, Plans, and Approvals

- **Priority:** High (Direct Tenant Isolation)
- **Component:** `app/Http/Controllers/Soar/SoarOrchestrationController.php`, `app/Services/SoarOrchestrationService.php`, SOAR migrations, `app/Models/SoarPlaybook.php` et al.
- **Finding:** None of the SOAR tables contain a `tenant_id` column, and they are completely un-scoped. Any tenant user can view, run simulations on, and approve/reject active response playbooks and escalation plans belonging to other tenants.
- **Proposed Fix:** Add `tenant_id` columns to all SOAR tables, register them in `TenantBoundaryService::ISOLATED_TABLES`, and refactor `SoarOrchestrationController` and `SoarOrchestrationService` to filter all queries by tenant context.

## Proposed Task: HUNT-TENANCY-GAP — Implement Tenant Scoping for Threat Hunting Queries and Pivoting

- **Priority:** High (Direct Tenant Isolation)
- **Component:** `app/Services/ThreatHuntingService.php`, `app/Http/Controllers/Security/ThreatHuntController.php`, `app/Http/Controllers/Api/ThreatHuntApiController.php`
- **Finding:** `ThreatHuntingService` executes hunts and pivot searches globally across all hosts/events without scoping queries by the tenant context. Pivot explorer and dashboard query hosts globally.
- **Proposed Fix:** Scope all queries in `executeQuery` and pivot helpers by the active tenant ID, add `tenant_id` columns to the threat hunt tables (already registered under `ISOLATED_TABLES`), and segment lists in the controllers.
