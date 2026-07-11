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
| **ENT-SDLC-NO-SUPPLYCHAIN** (base-image digest pinning + SBOM generation done; scan/sign remain) | [Enterprise SDLC] Python `requirements.txt` already pin exact versions and Go services have zero external deps — already fine. Dockerfiles now pinned to resolved digests. `scripts/xdr_generate_sbom.py` now generates a CycloneDX 1.5 SBOM per service (`docs/security/sbom/*.cyclonedx.json`) directly from requirements.txt/go.mod/digest-pinned Dockerfiles — no syft binary needed. Still missing: image vuln scan gate (trivy), signed builds (cosign) — neither tool is installable/verifiable in this environment | `services/*/Dockerfile`, `scripts/xdr_generate_sbom.py`, CI | Medium | Proposed (reduced) |
| **IDENTITY-SSO-MFA** (TOTP MFA + mandatory enforcement on approval routes done; SSO/SAML/OIDC federation remains) | [Enterprise BLOCKER — re-ranked] Per-user opt-in TOTP now implemented (`TotpService`, RFC 6238, dependency-free — verified against the RFC's own published test vector, not just self-consistency). Login gates on a 6-digit code when a user has enabled it; existing password-only login is unaffected for everyone else. Mandatory MFA enforcement now wired (`EnsureMfaVerified`/`mfa.required` middleware, gated by `SOC_MFA_ENFORCEMENT_ENABLED`, default `false`) on response-plan/active-response/data-erasure approve routes. Still missing: real SSO/SAML/OIDC federation to a corporate IdP (Okta/Azure AD) — needs a real external IdP to configure and test against, which this environment doesn't have | `app/Services/TotpService.php`, `app/Http/Controllers/Auth/MfaController.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Middleware/EnsureMfaVerified.php`, `routes/auth.php`, `routes/web.php` | High | Proposed (reduced) |
| **OBS-OTEL-TRACING** (phases 1-4 done — W3C traceparent across all 6 hops + OTLP/HTTP span export wired for the 3 core Go pipeline services; Python/PHP OTLP export remains) | See detail section below | `services/{ingestion-gateway,normalizer-worker,correlation-worker}/main.go`, `app/Http/Middleware/*` | High | Proposed (reduced) |
| **CODE-STRUCT-DECOMPOSE** (correlation-worker, normalizer-worker, alert-writer-service, incident-builder-service, ThreatHuntingService, ReportExportService, UEBABaselineService, EntityRiskScoringService decomposed; Pandaproxy/Kafka transport intentionally remains) | See detail section below | see detail section | Medium | Proposed (reduced) |
| **CONNECTOR-FRAMEWORK** (phases 1-7 done — syslog/CEF/LEEF/parser-registry/CloudTrail/GuardDuty/GCP/O365; all connectors also have CONN-UNTENANTED-INGEST/CONN-DELIVERY-LOSS/CONN-UNBOUNDED-FILE hardening) | All 7 phases complete — see `REVIEW_COMPLETED.md` for full per-phase detail. Phase 7 (O365 Management Activity API) is a live pull-API poller, materially different from the 3 file-based cloud connectors — built and unit-tested (67 tests) against a local mock OAuth+Activity API server since this environment has no real Azure AD app registration to verify against. | `services/ingestion-gateway`, `services/normalizer-worker`, `services/log-connector-*` | High | Done |
| **DATA-TIERING** (phases 1/2/2b done — archive-then-prune, searchable local archive, RBAC-gated UI; warm/cold infra tiers remain) | See detail section below | `app/Services/SecurityRetentionArchiveService.php`, `app/Services/ArchiveSearchService.php` | Medium | Proposed (reduced) |
| **SIM-LAYER-REALITY-GATE** (Track B only — Track A done) | [Dummy → must be real] Track A (labelling) done: all 35 HA/scale/chaos/soak/pilot validation-run tables now carry `is_simulated`/`evidence_basis`. Remaining: Track B — back the key validators (HA failover, scale, soak) against a real multi-node harness (`docker-compose.ha.yml`) so they produce *measured*, not just *computed*, evidence | `app/Services/EnterpriseScaleHaService.php`, `TelemetryScalePilotService.php`, `SoakChaosValidationService.php`, `PilotExecutionService.php`, `docker-compose.ha.yml` | High | Proposed (reduced) |
| **ARCH-KAFKA-NATIVE** | [Enterprise throughput — promoted from footnote 2026-07-06] Go workers talk to Redpanda via Pandaproxy HTTP REST (serialization + no compression + per-op TCP) instead of native binary Kafka (franz-go/sarama, port 9092). At enterprise throughput this is a real latency/CPU ceiling, not a demo nicety. GATE: live-pipeline verifier + offset-recovery/poison-DLQ regression per CLAUDE.md | `services/{ingestion-gateway,normalizer-worker,correlation-worker}/main.go` | High | Proposed (staged — needs live-pipeline validation) |
| **PERF-REST-POLL** | [Enterprise throughput — promoted 2026-07-06] Consumer loops long-poll Pandaproxy REST `/records`; native Kafka consumer removes the REST round-trip overhead. Bundle with ARCH-KAFKA-NATIVE (same transport rewrite). GATE: live-pipeline verifier | `services/{normalizer-worker,correlation-worker}/main.go` | Medium | Proposed (staged) |
| **PERF-REST-REBALANCE** | [Enterprise reliability — promoted 2026-07-06] Stable consumer instance IDs to avoid REST rebalance storms on restart; touches the hardened consumer-offset-recovery path (see CONSUMER-GROUP-EPHEMERAL, done). GATE: live-pipeline verifier | `services/{alert-writer,incident-builder}-service/main.py`, Go workers | Medium | Proposed (staged) |
| **ARCH-DB-SPLIT** | [Enterprise scale — promoted 2026-07-06] Alert/telemetry write-path lands on OLTP Postgres; route high-volume telemetry to ClickHouse (OLAP) and reserve PG for relational/SOC state so dashboards don't contend with ingest. Infra redesign — needs live ClickHouse + load test | `services/alert-writer-service/main.py`, ClickHouse, PG | High | Proposed (staged — infra) |
| **ARCH-DISCOVERY** | [Enterprise infra — promoted 2026-07-06] Static hostnames only; multi-node needs DNS/service discovery + internal LB. Belongs with a real multi-node deploy (ties ENT-REL-SIMULATED-HA / HA-DRILL-01) | `docker-compose*.yml`, deploy manifests | Medium | Proposed (staged — infra) |
| **AI-KB-SEMANTIC** | [Enterprise AI — promoted 2026-07-06] Qdrant + cosine ranking path exists (`SocKnowledgeRetriever::retrieveQdrant`); only a live transformer embedding model is missing (currently offline pseudo-embeddings). Needs a bundled/served embedding model — conflicts with offline-first default, so gate behind a flag | `app/Support/SocKnowledgeRetriever.php`, embedding service | Medium | Proposed (staged — needs ML infra) |

> **This file tracks only pending/open tasks.** Completed tasks live in `REVIEW_COMPLETED.md`; rejected/deferred in `REVIEW_REJECTED.md`.
>
> **Classified out (2026-06-29):** `EDR-EXEC-02` and `AI-CONF-BANDS` → REJECTED (forbidden: automated active containment). `TENANT-ENFORCE-RLS` → DEFERRED (gated by RLS_DECISION_RECORD + GAP-002/003). See REVIEW_REJECTED.md.
>
> **Promoted from footnote → actionable rows (2026-07-06):** the BATCH-DEFER-2026-07-01 cluster is now open task rows above (`ARCH-KAFKA-NATIVE`, `PERF-REST-POLL`, `PERF-REST-REBALANCE`, `PERF-GO-LIMITER`, `PERF-GO-OVERCONCURRENT`, `ARCH-DB-SPLIT`, `ARCH-DISCOVERY`, `AI-KB-SEMANTIC`, `AI-KB-FEED-INGEST`; `ARCH-MTLS-SEC` is covered by `ENT-SEC-NO-TLS-INTERNAL`). They remain **staged** — each carries its CLAUDE.md validation gate (live-pipeline verifier for hot-path/transport, Go race tests, or real infra) and must not be batched. This makes them visible/triage-able instead of hidden in a note.

---

## Proposed Task: IDENTITY-SSO-MFA — Enterprise SSO (SAML/OIDC) + MFA for analyst auth

- **Priority:** High
- **Component:** `app/Services/TotpService.php`, `app/Http/Controllers/Auth/MfaController.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Middleware/EnsureMfaVerified.php`
- **Status:** TOTP MFA done + mandatory MFA enforcement on approval routes done (see `REVIEW_COMPLETED.md` for full detail — `TotpService`, RFC 6238, dependency-free, verified against the RFC's published test vector; per-user opt-in 2-step login; `EnsureMfaVerified`/`mfa.required` middleware on response-plan/active-response/erasure approve routes, gated by `SOC_MFA_ENFORCEMENT_ENABLED` default `false`; 29 tests total).
- **Remaining scope:** Real SSO/SAML/OIDC federation to a corporate IdP (Okta/Azure AD) — needs a real external IdP to configure/test against, unavailable in this environment.

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
  (no live OTel collector available in this environment).
- **Remaining scope:** OTLP export for the 2 Python services (`alert-writer-service`,
  `incident-builder-service`) and PHP (`TraceparentService`/`SecurityRequestLogger`) — same
  wiring pattern as the Go phase, not yet done. A real live OTel collector
  (compose `observability` profile) to actually stitch/visualize the emitted spans (Tempo/
  Jaeger) also remains — this environment can only verify the exporter's wire format and HTTP
  behavior against a mock server, not a real collector's ingestion/UI.

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
- **Proposed fix:** Wire `EnterpriseScaleHaService`/soak validators to the real `docker-compose.ha.yml`
  multi-node path (ties into GAP-004 / HA-DRILL-01) so at least HA-failover and soak can produce
  *measured* evidence (`evidence_basis='measured'`) before any production-readiness claim.
- **Safety:** Real validation harness only; advisory-only records preserved; no autonomous action;
  append-only tables untouched.









