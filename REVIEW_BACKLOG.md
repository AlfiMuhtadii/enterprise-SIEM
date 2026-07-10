# Pending Hardening & Cleanup Tasks (Backlog)

This file tracks all pending security hardening, refactoring, and documentation alignment tasks.
It is synchronized with GitHub Issues. Developers/writing agents (e.g., Claude) should resolve these tasks.

---

## Open Backlog Tasks

| Task ID | Title | File / Component | Priority | Status |
|---|---|---|---|---|
| **ENT-SEC-NO-TLS-INTERNAL** (phases 1-3 done — ingestion-gateway/normalizer-worker/correlation-worker mTLS, off by default; other hops remain) | [Enterprise BLOCKER] Cert generation (`scripts/xdr_generate_internal_mtls_certs.py`) and a reusable `internal/mtls` Go package (`ServerConfig`/`ClientConfig`, requires+verifies client certs) now exist and are wired into all 3 core Go pipeline services' listeners and outbound `httpClient`s, gated behind `XDR_INTERNAL_MTLS_ENABLED` (default `false` — zero behavior change until an operator opts in). Verified with real end-to-end handshake smoke tests per service (binary built and run, real generated certs, no-cert request rejected / valid-cert request succeeds) in addition to unit tests. Still missing: alert-writer-service, incident-builder-service, ai-rag-service (Python), 4 log-connector-* services, Pandaproxy/OpenSearch plaintext HTTP, Postgres sslmode, and the docker-compose network wiring to actually run mTLS between containers — these phases prove the mechanism across the core pipeline, they do not cover "any internal hop" yet | `services/{ingestion-gateway,normalizer-worker,correlation-worker}/internal/mtls/`, `scripts/xdr_generate_internal_mtls_certs.py`, `app/Services/InternalAuthService.php`, DB DSNs | High | Proposed (reduced) |
| **ENT-TENANCY-NO-DB-ENFORCEMENT** | [Enterprise BLOCKER] Isolation is app-layer `where('tenant_id')` only (ASSET-TENANT-OVERWRITE proves it leaks); no DB RLS. Supersedes deferred TENANT-ENFORCE-RLS — mandatory for multi-tenant SaaS | `app/Services/TenantBoundaryService.php`, Postgres RLS | High | Proposed (re-open TENANT-ENFORCE-RLS) |
| **ENT-REL-SIMULATED-HA** | [Enterprise BLOCKER] HA/scale/DR "PASS" is computed, not measured on real cluster (SIM-LAYER Track B + HA-DRILL-01). "Too heavy for laptop" invalid at enterprise bar — run on real staging before any availability claim | `app/Services/EnterpriseScaleHaService.php` et al., `docker-compose.ha.yml` | High | Proposed (re-open Track B + HA-DRILL-01) |
| **ENT-DETECT-ML-NOT-LIVE** (investigated — original proposed fix is wrong integration point) | [Enterprise product-claim BLOCKER] Model scores **HTTP-request** features (`status`/`latency_ms`/`has_sql_keywords`/...), confirmed identical to `security_events` (Laravel's `SecurityRequestLogger`), NOT correlation-worker's identity/cloud/SaaS telemetry — wiring it into correlation-worker as originally proposed would score data it was never trained on. The real rule+ML hybrid already exists as working code (`scripts/realtime_detector_consumer.py`) but isn't in `docker-compose.yml` at all, and it writes directly to the **active** `security_alerts` table with no soak gate — deploying it live as-is risks silently activating an unvalidated new alert domain | `scripts/realtime_detector_consumer.py`, `docker-compose.yml`, `app/Http/Middleware/SecurityRequestLogger.php` | High | Deferred — needs advisory-first soak plan, see detail section |
| **ENT-SDLC-NO-SUPPLYCHAIN** (base-image digest pinning + SBOM generation done; scan/sign remain) | [Enterprise SDLC] Python `requirements.txt` already pin exact versions and Go services have zero external deps — already fine. Dockerfiles now pinned to resolved digests. `scripts/xdr_generate_sbom.py` now generates a CycloneDX 1.5 SBOM per service (`docs/security/sbom/*.cyclonedx.json`) directly from requirements.txt/go.mod/digest-pinned Dockerfiles — no syft binary needed. Still missing: image vuln scan gate (trivy), signed builds (cosign) — neither tool is installable/verifiable in this environment | `services/*/Dockerfile`, `scripts/xdr_generate_sbom.py`, CI | Medium | Proposed (reduced) |
| **IDENTITY-SSO-MFA** (TOTP MFA done; SSO/SAML/OIDC federation remains) | [Enterprise BLOCKER — re-ranked] Per-user opt-in TOTP now implemented (`TotpService`, RFC 6238, dependency-free — verified against the RFC's own published test vector, not just self-consistency). Login gates on a 6-digit code when a user has enabled it; existing password-only login is unaffected for everyone else. Still missing: real SSO/SAML/OIDC federation to a corporate IdP (Okta/Azure AD) — needs a real external IdP to configure and test against, which this environment doesn't have; enforcing MFA as *mandatory* for specific roles/routes (`soc:response.*`/`soc:admin.*`) is also still a policy decision, not yet wired | `app/Services/TotpService.php`, `app/Http/Controllers/Auth/MfaController.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `routes/auth.php` | High | Proposed (reduced) |
| **OBS-OTEL-TRACING** (phases 1-3 done — W3C traceparent across all 6 hops; OTLP collector wiring remains) | See detail section below | `services/*/main.*`, `app/Http/Middleware/*` | High | Proposed (reduced) |
| **CODE-STRUCT-DECOMPOSE** (correlation-worker, normalizer-worker, alert-writer-service, incident-builder-service, ThreatHuntingService, ReportExportService, UEBABaselineService, EntityRiskScoringService decomposed; Pandaproxy/Kafka transport intentionally remains) | See detail section below | see detail section | Medium | Proposed (reduced) |
| **CONNECTOR-FRAMEWORK** (phases 1-6 done — syslog/CEF/LEEF/parser-registry/CloudTrail/GuardDuty/GCP; O365 Management Activity API remains) | See detail section below | `services/ingestion-gateway`, `services/normalizer-worker`, `services/log-connector-*` | High | Proposed (reduced) |
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
- **Component:** `app/Services/TotpService.php`, `app/Http/Controllers/Auth/MfaController.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`
- **Status:** TOTP MFA done (see `REVIEW_COMPLETED.md` for full detail — `TotpService`, RFC 6238, dependency-free, verified against the RFC's published test vector; per-user opt-in 2-step login; 21 tests).
- **Remaining scope:** Real SSO/SAML/OIDC federation to a corporate IdP (Okta/Azure AD) — needs a real external IdP to configure/test against, unavailable in this environment. Mandatory MFA enforcement on specific roles/routes (`soc:response.*`/`soc:admin.*`) is a policy decision, not yet wired.

## Proposed Task: OBS-OTEL-TRACING — Standards-based distributed tracing across polyglot services

- **Priority:** Medium
- **Component:** `services/ingestion-gateway`, `services/normalizer-worker`,
  `services/correlation-worker`, `services/alert-writer-service`,
  `services/incident-builder-service`, `app/Services/TraceparentService.php`
- **Status:** Phases 1-3 done (see `REVIEW_COMPLETED.md`) — W3C Trace Context `traceparent`
  generation/parsing/propagation implemented dependency-free across all 6 hops: Go
  (`internal/traceparent`, 3 services), Python (`traceparent.py`, 2 services), PHP
  (`TraceparentService`, wired into `SecurityRequestLogger`). 84 tests total across the 3
  phases, all green.
- **Remaining scope:** Wiring to a real OTLP collector/OTel SDK — every hop produces
  standards-shaped span context, but nothing anywhere emits or collects an actual OTel span
  yet, so no tool like Tempo/Jaeger can currently stitch them. Needs a live collector
  (compose `observability` profile) to build and verify against.

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

## Proposed Task: CONNECTOR-FRAMEWORK — Generic log-ingestion / connector & parser framework

- **Priority:** High (the single biggest capability gap)
- **Status:** Phases 1-6 done (see `REVIEW_COMPLETED.md` for full per-phase detail):
  - Phase 1: syslog + CEF receiver (`services/log-connector-syslog`, `internal/cef`).
  - Phase 2: LEEF parser (`internal/leef`).
  - Phase 3: config-driven parser registry (`internal/registry`) — onboard new sources by
    JSON config, zero `normalizer-worker` code changes.
  - Phase 4: AWS CloudTrail connector (`services/log-connector-cloudtrail`, file-based).
  - Phase 5: AWS GuardDuty connector (`services/log-connector-guardduty`, NDJSON findings).
  - Phase 6: GCP Cloud Audit Logs connector (`services/log-connector-gcp-audit`, NDJSON).
  All events still flow through the existing normalize→correlate shadow path — no new active
  alert domain, no rule changes. All 3 cloud connectors are deliberately file-based ingestion
  of already-exported logs, not live API polling (would need real cloud credentials/SigV4
  unavailable in this environment).
- **Remaining scope:** O365 Management Activity API — a materially different integration
  shape from the 3 cloud connectors above (pull-based subscription + continuation-token
  polling, not a file-export-to-object-storage pattern), needs real OAuth/API-key credentials
  to build and test against.

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










