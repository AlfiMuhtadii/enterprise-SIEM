# Pending Hardening & Cleanup Tasks (Backlog)

This file tracks all pending security hardening, refactoring, and documentation alignment tasks.
It is synchronized with GitHub Issues. Developers/writing agents (e.g., Claude) should resolve these tasks.

---

### Open Backlog Tasks

### Tabel A: Tugas Pengembangan Lokal & Simulasi (Nol Modal / Gratis)

| Task ID | Title | File / Component | Priority | Status |
|---|---|---|---|---|
| **ENT-TENANCY-NO-DB-ENFORCEMENT** | [Enterprise BLOCKER] Isolation is app-layer `where('tenant_id')` only (ASSET-TENANT-OVERWRITE proves it leaks); no DB RLS. Supersedes deferred TENANT-ENFORCE-RLS — mandatory for multi-tenant SaaS | `app/Services/TenantBoundaryService.php`, Postgres RLS | High | Ongoing (Codex, 2026-08-28) |
| **CODE-STRUCT-DECOMPOSE** (correlation-worker, normalizer-worker, alert-writer-service, incident-builder-service, ThreatHuntingService, ReportExportService, UEBABaselineService, EntityRiskScoringService decomposed; Pandaproxy/Kafka transport intentionally remains) | See detail section below | see detail section | Medium | Proposed (reduced) |
| **ARCH-DB-SPLIT** (write path + soak tooling + live verification done 2026-07-12; 5 of 7+ read paths migrated and live-verified 2026-07-14 — dashboard domain breakdown, single-host endpoint timeline, threat hunt search, forensic collection, detection backtest; only the 2 correlation detectors remain, deliberately excluded) | [Enterprise scale — promoted 2026-07-06] Both `telemetry_events` write paths (Python batch/stream ingesters + `AgentIngestionController::telemetry()`) now route to a new ClickHouse `telemetry_events` table (with `tenant_id` from day one) behind `XDR_TELEMETRY_WRITE_TARGET` (default `postgres`, zero behavior change). `scripts/load_test_soc.py` gained a real concurrent write-throughput soak generator (`--ingest-target`/`--ingest-concurrency`). Ran this for real against Docker-backed infra: found and fixed a genuine bug (ClickHouse's default `DateTime64` parser rejects plain ISO-8601 timestamps — `date_time_input_format=best_effort` added to the HTTP query URL), then captured real measured evidence — ClickHouse ~29-54% higher write throughput than Postgres across concurrency levels, and a real dashboard-style aggregation query against Postgres slowed 3.8x on average (9.4x at p95) under a concurrent 16-worker telemetry write soak. Read-path phase 1 (2026-07-14): `App\Services\ClickHouseTelemetryReader` migrated the 2 lowest-risk read sites — dashboard domain breakdown and single-host endpoint timeline. Read-path phase 2 (2026-07-14): 3 more methods added — `huntSearch()` (mirrors `SocHuntController`'s multi-filter free-text search, including the `payload ILIKE` domain search — ClickHouse's `payload` is a plain `String` column so this needs no `::text` cast the way Postgres's does), `forensicHostEvents()` (mirrors `SocForensicController`'s exact-host_id forensic bundle lookup), and `identityCloudSaasWindow()` (mirrors `DetectionBacktestService`'s identity/cloud/saas replay window — safe to migrate despite reading the same telemetry_type range the 2 excluded correlation detectors also read, since this service only ever writes to the advisory-only `detection_backtest_runs`/`detection_backtest_matches` tables, never to `security_alerts`/`security_incidents`). All 5 read sites fall back to Postgres on any ClickHouse failure so none of them ever just break. `TENANT-CLICKHOUSE-LEAK` (2026-07-14, see `REVIEW_COMPLETED.md`) closed the ClickHouse side of tenant scoping for 4 of these 5 methods (all but the global/admin-only `identityCloudSaasWindow`) — the Postgres fallback still has no tenant_id column on telemetry_events at all (`TenantBoundaryService::UNISOLATED_TABLES`), a separate, larger, structural gap belonging with `ENT-TENANCY-NO-DB-ENFORCEMENT`. Only the 2 correlation detectors (`scripts/xdr_correlation_detector.py`'s `detect_identity()`/`detect_cloud_saas()`) remain explicitly out of scope — a silent output change there is a correctness incident, not a dashboard/hunt/forensics page showing slightly stale data | `app/Services/ClickHouseTelemetryReader.php`, `app/Http/Controllers/SocDashboardController.php`, `app/Http/Controllers/SocEndpointTimelineController.php`, `app/Http/Controllers/SocHuntController.php`, `app/Http/Controllers/SocForensicController.php`, `app/Services/DetectionBacktestService.php`, `scripts/ingest_telemetry_events.py`, `scripts/telemetry_stream_worker.py`, `scripts/xdr_infra_clients.py`, `scripts/load_test_soc.py`, `app/Services/ClickHouseTelemetryWriter.php`, `app/Http/Controllers/AgentIngestionController.php` | High | Proposed (reduced) |

### Tabel B: Tugas Infrastruktur Produksi & Layanan Cloud (Memerlukan Modal / Cloud)

| Task ID | Title | File / Component | Priority | Status |
|---|---|---|---|---|
| **ENT-SEC-NO-TLS-INTERNAL** (phases 1-20 done - infrastructure transport, internal services, and endpoint-agent mTLS client are protected) | [Enterprise BLOCKER - reduced] Phase 20 adds opt-in, fail-closed mTLS to the canonical endpoint agent ingestion client without changing local plaintext defaults. The stdlib client requires HTTPS plus complete CA/client-cert/client-key paths, preserves hostname verification, and never bundles endpoint private keys in deployment packages. Live proof sent a real HMAC-signed agent event through an mTLS-enabled rebuilt ingestion gateway and rejected a client without a certificate. Remaining scope includes mTLS support for all five Go log connectors and Laravel/scenario tooling before enabling the ingestion-gateway server in production, plus Ollama, Tempo/OTLP, and MinIO where enabled. | `services/endpoint-agent/{agent.py,config.json.example,README.md}`, `tests/endpoint_agent/test_agent.py` | High | Proposed (reduced) |
| **ENT-REL-SIMULATED-HA** | [Enterprise BLOCKER] HA/scale/DR "PASS" is computed, not measured on real cluster (SIM-LAYER Track B + HA-DRILL-01). "Too heavy for laptop" invalid at enterprise bar — run on real staging before any availability claim | `app/Services/EnterpriseScaleHaService.php` et al., `docker-compose.ha.yml` | High | Proposed (re-open Track B + HA-DRILL-01) |
| **ENT-SDLC-NO-SUPPLYCHAIN** (base-image digest pinning, SBOM, dependency scan, container scan, signed release publication, deployment verifier, and release vulnerability gate implemented) | [Enterprise SDLC - reduced] Every canonical release digest is scanned before signing under `release-critical-v1`: `CRITICAL` findings (fixed or unfixed), mutable references, and scanner/report/auth failures block; `HIGH` remains retained advisory evidence. The scanner now copies only inline registry-scoped credentials into a permission-restricted ephemeral config and strips host-only credential helpers before mounting it read-only. Live Docker proof: an immutable public registry digest passed remote policy, while `detector-ci-app:latest` was blocked with exit 2 for 16 CRITICAL findings. Every per-image workflow report is retained for 90 days. Remaining scope is a real private-GHCR signed-release/live deployment run; no staging target currently exists. | `.github/workflows/release.yml`, `scripts/xdr_container_image_scan.py`, `scripts/xdr_release_{manifest,verify}.py`, `docs/operations/{RELEASE_VULNERABILITY_POLICY,IMMUTABLE_RELEASE_VERIFICATION}.md` | Medium | Proposed (reduced; credential-helper remediation live-verified by Codex, 2026-08-27) |
| **IDENTITY-SSO-MFA** (TOTP MFA + mandatory enforcement + OIDC SSO federation + SAML SSO federation all done) | [Enterprise BLOCKER — re-ranked] Per-user opt-in TOTP now implemented (`TotpService`, RFC 6238, dependency-free — verified against the RFC's own published test vector, not just self-consistency). Login gates on a 6-digit code when a user has enabled it; existing password-only login is unaffected for everyone else. Mandatory MFA enforcement now wired (`EnsureMfaVerified`/`mfa.required` middleware, gated by `SOC_MFA_ENFORCEMENT_ENABLED`, default `false`) on response-plan/active-response/data-erasure approve routes. OIDC authorization-code SSO federation now implemented (`OidcSsoService`/`OidcSsoController`, gated by `SOC_OIDC_SSO_ENABLED`, default `false`) — real RS256 ID-token signature verification via `firebase/php-jwt` + JWKS. SAML 2.0 SP-initiated SSO federation now also implemented (`SamlSsoService`/`SamlSsoController`, gated by `SOC_SAML_SSO_ENABLED`, default `false`) — real XML-DSig assertion-signature verification + XSD schema validation via `onelogin/php-saml` (the other place, alongside OIDC's `firebase/php-jwt`, this codebase's usual "dependency-free protocol implementation" convention was deliberately not followed — hand-rolling XML signature verification is a well-known vulnerability source, XML signature wrapping specifically). Both federation paths never auto-provision accounts — only an existing user matched by a verified/signed identity claim can sign in; per-user TOTP is still enforced after SSO login either way. Verified against real local mock IdPs (genuine ephemeral keypairs, real signed/verified tokens/assertions) since no live external IdP exists in this environment. Still missing: end-to-end verification against a real corporate IdP (Okta/Azure AD/ADFS) for either protocol — needs a real external IdP this environment doesn't have; `/sso/saml/metadata` is the concrete handoff point for that future pass | `app/Services/TotpService.php`, `app/Services/OidcSsoService.php`, `app/Services/SamlSsoService.php`, `app/Http/Controllers/Auth/MfaController.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Controllers/Auth/OidcSsoController.php`, `app/Http/Controllers/Auth/SamlSsoController.php`, `app/Http/Middleware/EnsureMfaVerified.php`, `config/oidc.php`, `config/saml.php`, `routes/auth.php`, `routes/web.php` | High | Proposed (reduced) |
| **DATA-TIERING** (phases 1/2/2b/3 done — archive-then-prune, searchable local archive, RBAC-gated UI, real ClickHouse warm tier; cold tier remains) | See detail section below | `app/Services/SecurityRetentionArchiveService.php`, `app/Services/ArchiveSearchService.php`, `app/Services/ClickHouseArchiveWriter.php`, `app/Services/ClickHouseArchiveSearchService.php` | Medium | Proposed (reduced) |
| **SIM-LAYER-REALITY-GATE** (Track B only — Track A done) | [Dummy → must be real] Track A (labelling) done: all 35 HA/scale/chaos/soak/pilot validation-run tables now carry `is_simulated`/`evidence_basis`. Remaining: Track B — back the key validators (HA failover, scale, soak) against a real multi-node harness (`docker-compose.ha.yml`) so they produce *measured*, not just *computed*, evidence | `app/Services/EnterpriseScaleHaService.php`, `TelemetryScalePilotService.php`, `SoakChaosValidationService.php`, `PilotExecutionService.php`, `docker-compose.ha.yml` | High | Proposed (reduced) |
| **ARCH-DISCOVERY** | [Enterprise infra — promoted 2026-07-06] Static hostnames only; multi-node needs DNS/service discovery + internal LB. Belongs with a real multi-node deploy (ties ENT-REL-SIMULATED-HA / HA-DRILL-01) | `docker-compose*.yml`, deploy manifests | Medium | Proposed (staged — infra) |

> **This file tracks only pending/open tasks.** Completed tasks live in `REVIEW_COMPLETED.md`; rejected/deferred in `REVIEW_REJECTED.md`.

### Active Phase: ENT-SEC-NO-TLS-INTERNAL - PostgreSQL Mutual TLS

- **Status:** Bounded PostgreSQL mTLS phase completed (Codex, 2026-08-28) - commit `0b2b3e1` enables production fail-closed TLS, requires CA-signed client certificates, mounts credentials read-only for all seven Compose database clients, and enforces `verify-full`. Live non-root proof negotiated TLS 1.3 (`TLS_AES_256_GCM_SHA384`); plaintext, missing-client-certificate, and untrusted-CA probes failed as required. Local validation passed 153 script tests, 121 incident-builder tests, the alert-writer suite, 9 certificate-generator tests, 3 Laravel TLS tests, Python adapter drift 10/10, and the 54-check clean-HEAD Compose gate. Remote runs `33167691112` (`ci`), `33167691087` (`phase9-contract`), and `33167691143` (`security-scan`) passed. Remaining parent-task scope is non-PostgreSQL internal infrastructure transport.
>
> **Classified out (2026-06-29):** `EDR-EXEC-02` and `AI-CONF-BANDS` → REJECTED (forbidden: automated active containment). `TENANT-ENFORCE-RLS` → DEFERRED (gated by RLS_DECISION_RECORD + GAP-002/003). See REVIEW_REJECTED.md.
>
> **Promoted from footnote → actionable rows (2026-07-06):** the BATCH-DEFER-2026-07-01 cluster is now open task rows above (`ARCH-KAFKA-NATIVE`, `PERF-REST-POLL`, `PERF-REST-REBALANCE`, `PERF-GO-LIMITER`, `PERF-GO-OVERCONCURRENT`, `ARCH-DB-SPLIT`, `ARCH-DISCOVERY`, `AI-KB-SEMANTIC`; `ARCH-MTLS-SEC` is covered by `ENT-SEC-NO-TLS-INTERNAL`). Each carries its CLAUDE.md validation gate (live-pipeline verifier for hot-path/transport, Go race tests, or real infra) and must not be batched with unrelated work. `ARCH-KAFKA-NATIVE`/`PERF-REST-POLL`/`PERF-REST-REBALANCE` moved from staged to reduced 2026-07-12 (code done, feature-flagged off, kfake-tested — see the bundled row above and `REVIEW_COMPLETED.md`); the rest remain **staged**. `AI-KB-FEED-INGEST` was completed 2026-07-10 (re-scoped to a bundled offline dataset import) — see `REVIEW_COMPLETED.md`. `AI-KB-SEMANTIC` was completed 2026-07-13 (real Qdrant vector search + optional real embedding model, [#59](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/59)) — see `REVIEW_COMPLETED.md`. `ARCH-KAFKA-NATIVE`/`PERF-REST-POLL`/`PERF-REST-REBALANCE` completed 2026-07-13 — live-pipeline verification against a real Redpanda broker done, a second real bug found+fixed (idle-poll misclassified as a fatal error), see `REVIEW_COMPLETED.md`. The resulting `PANDAPROXY-EARLIEST-RESET-BUG` blocker was completed 2026-07-14 by adding native Kafka transport to both downstream Python services; see `REVIEW_COMPLETED.md`.

---

## Proposed Task: IDENTITY-SSO-MFA — Enterprise SSO (SAML/OIDC) + MFA for analyst auth

- **Priority:** High
- **Component:** `app/Services/TotpService.php`, `app/Services/OidcSsoService.php`, `app/Services/SamlSsoService.php`, `app/Http/Controllers/Auth/MfaController.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Controllers/Auth/OidcSsoController.php`, `app/Http/Controllers/Auth/SamlSsoController.php`, `app/Http/Middleware/EnsureMfaVerified.php`, `config/oidc.php`, `config/saml.php`
- **Status:** TOTP MFA + mandatory enforcement + OIDC SSO federation + SAML SSO federation all done (see `REVIEW_COMPLETED.md` for full detail — `TotpService`, RFC 6238, dependency-free, verified against the RFC's published test vector; per-user opt-in 2-step login; `EnsureMfaVerified`/`mfa.required` middleware on response-plan/active-response/erasure approve routes, gated by `SOC_MFA_ENFORCEMENT_ENABLED` default `false`; `OidcSsoService`/`OidcSsoController`, authorization-code flow + real JWKS-based RS256 ID-token verification via `firebase/php-jwt`, gated by `SOC_OIDC_SSO_ENABLED` default `false`; `SamlSsoService`/`SamlSsoController`, SP-initiated SAML 2.0 + real XML-DSig/XSD verification via `onelogin/php-saml`, gated by `SOC_SAML_SSO_ENABLED` default `false`; both federation paths never auto-provision accounts and are verified against real local mock IdPs; 49 new tests total across all phases).
- **Remaining scope & Local Verification Workaround:** 
  * The remaining work involves end-to-end verification against a real corporate IdP (Okta/Azure AD/ADFS) for both OIDC and SAML.
  * **Local Workaround:** Developers can build and run lightweight local mock IdPs using Node.js (e.g., `oidc-provider` library for OIDC, or the `saml-idp` npm package for SP-initiated SAML 2.0) running on custom host ports (e.g., `http://localhost:9000`). By updating the local `.env` and `config/oidc.php` or `config/saml.php` to point to these local host endpoints, developers can perform live OIDC/SAML logins and signatures checks locally.

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
- **Status:** Phases 1/2/2b/3 done (see `REVIEW_COMPLETED.md` for full detail):
  - Phase 1: `SecurityRetentionArchiveService` — archive-then-prune (gzip JSONL) instead of
    hard delete, replacing the old direct-delete behavior by default.
  - Phase 2: `ArchiveSearchService` — bounded local search over the archive
    (`security:archive-search` Artisan command), honestly labelled as a local safety-net
    search, not a real indexed warm tier.
  - Phase 2b: `ArchiveSearchController` — RBAC-gated (`soc:search.view`) UI so an analyst can
    search the archive without shell access; tenant scope derived from
    `TenantContextAuthority`, not a free-text field.
  - Phase 3 (2026-07-13, once Docker became available): the real warm tier. New generic
    `archived_records` ClickHouse table (one table across `security_events`/
    `security_alerts`/`security_incidents` — their column shapes differ, so
    `source_table`/`tenant_id`/`original_ts` are promoted to real indexed columns while the
    full original row stays as a JSON `payload`) plus `ClickHouseArchiveWriter`/
    `ClickHouseArchiveSearchService`, gated by `XDR_DATA_TIERING_WARM_TIER_ENABLED` (default
    `false` — zero behavior change; the gzip archive stays the durability guarantee either
    way, ClickHouse is an additional indexed search path, not a replacement).
    `ArchiveSearchController` prefers the ClickHouse path when enabled and falls back to the
    gzip scan on any failure. Live-verified end-to-end against the real running ClickHouse:
    seeded a real `security_alerts` row, archived it with the warm tier on, confirmed the row
    landed in `archived_records` with the correct payload, then queried it back through
    `ClickHouseArchiveSearchService` with a tenant/date-range/exact-match filter and got the
    real result. 13 new tests, zero regression (full suite 5126 passed).
- **Remaining scope:** The cold tier (object storage archival/restore) — needs live infra
  this environment doesn't have; the local gzip archive is durable but not a substitute.

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

## Proposed Task: ENT-TENANCY-NO-DB-ENFORCEMENT — Enable strict PostgreSQL RLS policies

- **Work status:** Ongoing (Codex, 2026-08-29). Prerequisite phase completed in `c97b400`: authenticated web/API requests can now set a validated, transaction-local `app.tenant_id` through a feature-gated middleware. The context owner rejects empty tenants and pre-existing transactions, detects unbalanced callback transactions, and rolls back every nested level on failure. PostgreSQL mTLS validation passed 9 tests / 16 assertions. RLS policies remain disabled pending explicit owner-role, `FORCE ROW LEVEL SECURITY`, fail-open/system-context, and full table-coverage verification.
- **Priority:** High
- **Component:** PostgreSQL schema migrations, `app/Services/TenantBoundaryService.php`
- **Finding:** Currently, multi-tenant isolation is enforced at the application layer (`where('tenant_id')`). There is no active Row-Level Security (RLS) enforcement at the database layer.
- **Proposed Fix & Local Verification Workaround:**
  * Define and enable PostgreSQL RLS policies for all `ISOLATED_TABLES`. Wire them to read from the request-level Postgres context bridge (`app.tenant_id`) populated by the middleware.
  * **Local Workaround:** This is purely database-level logic and is 100% free with zero additional CPU/RAM usage. It can be verified locally against the existing PostgreSQL container.

## Proposed Task: ENT-REL-SIMULATED-HA (Track B) — Run 3-Broker Redpanda Cluster locally in Low-Resource Mode

- **Priority:** High
- **Component:** `docker-compose.ha.yml`, `app/Services/EnterpriseScaleHaService.php`
- **Finding:** HA and scale metrics are computed/simulated, not verified on a real cluster under load.
- **Proposed Fix & Local Verification Workaround:**
  * Configure and run the 3-node Redpanda cluster locally.
  * **Local Workaround:** To prevent high memory usage on developer machines, configure each Redpanda broker with low-resource flags:
    `redpanda start --memory 512M --reserve-memory 0M --default-log-bytes 100M`
    This keeps the total memory footprint under 1.5GB of RAM for the entire cluster.

## Proposed Task: CICD-IMMUTABLE-DELIVERY / K8S-FOUNDATION-GATE - Establish Release Artifacts Before Orchestration Migration

- **Status:** Proposed (reduced; immutable-artifact and release-vulnerability-gate phases completed by Codex, 2026-08-27) - the release workflow now gates publication on an exact existing SemVer tag whose commit is contained in `master`, builds all 13 first-party runtime images by canonical digest, blocks critical vulnerabilities and scanner failures before signing, emits BuildKit SBOM/provenance, signs and immediately verifies every digest with keyless Cosign/OIDC, creates GitHub artifact attestations, and validates a deterministic retained release manifest before attaching immutable release/commit tags. Docker Desktop credential-helper references are stripped from the scanner's ephemeral config while inline registry-scoped credentials are retained. Live proof covers a remote immutable public digest PASS and a vulnerable Detector image BLOCKED with exit 2; a real private-GHCR tagged release remains unproven. All release actions are pinned to full revisions, manual dispatch defaults to a non-publishing gate, and no deployment or Kubernetes mutation was added.
- **Remaining Scope:** Establish a real protected staging target and deployment-side signature/digest verification, execute a real tagged release to prove the new remote scan/sign chain, and prove rolling rollback without event loss. Kubernetes remains gated on those controls plus multi-host stateful recovery evidence.
- **Priority:** Medium (becomes High before SLA/commercial deployment)
- **Component:** GitHub Actions, container registry, image signing/provenance, deployment environments, future Helm/GitOps assets
- **Finding:** There is no CD workflow, immutable image publication, environment promotion, deployment approval, artifact retention, or automated rollback evidence. Kubernetes prerequisites are absent, while stateful dependencies remain single-node and HA validation is open.
- **Proposed Fix:** Build all first-party images once per commit, promote by digest, emit SBOM/provenance, complete signing under `ENT-SDLC-NO-SUPPLYCHAIN`, and add protected staging promotion. Make workloads Kubernetes-portable through probes, graceful shutdown, resource requests/limits, external secrets, and a deliberate managed-service/operator strategy for PostgreSQL, Redpanda, OpenSearch, ClickHouse, and Qdrant.
- **Adoption Gate:** Do not begin a full Kubernetes migration until protected CI is green, immutable signed artifacts exist, a multi-host HA/SLA requirement is approved, stateful recovery is proven, and staging demonstrates rolling upgrade plus rollback without event loss.

## Proposed Task: AGENT-CANONICAL-PACKAGING - Consolidate Endpoint Agent Source and Remove External Runtime Dependency

- **Work status:** Proposed (reduced; package integrity phase completed by Codex, 2026-08-27).
- **Priority:** High before external endpoint deployment
- **Component:** `services/endpoint-agent/agent.py`, `deploy/agent/`, `scripts/{build,verify}_agent_package.py`, `scripts/export_portable_detector.py`, package tests and deployment guides
- **Status (2026-07-16):** Source-of-truth ambiguity resolved. `scripts/build_agent_package.py`, `deploy/agent/windows/install-agent-service.ps1`, and `deploy/agent/linux/detector-endpoint-agent.service` now package/invoke the canonical `services/endpoint-agent/agent.py` (191 dedicated tests, matches the documented ingestion-gateway/Redpanda pipeline) via its real `--config config.json` interface, instead of the untested `scripts/endpoint_telemetry_agent.py` (retired/deleted — it bypassed the entire Go/Redpanda pipeline and posted straight to Laravel). `scripts/export_portable_detector.py` updated to bundle `services/endpoint-agent` (was missing entirely from portable exports). 4 docs files (`README_REALTIME_ENDPOINT_RESPONSE.md`, `README_AGENT_MANAGEMENT.md`, `README_ENDPOINT_AGENT_OPS.md`, `README_XDR_VALIDATION_PLAYBOOK.md`, `README_PHASE_NEXT_TELEMETRY_INTELLIGENCE.md`) rewritten to the real `agent.py` protocol (ingestion-gateway `X-XDR-Signature` for telemetry, SOC `X-Agent-Signature`/Bearer for control-plane) rather than presenting it as a drop-in CLI-flag swap. **2 real capability gaps found and documented honestly, not silently dropped:** (1) no generic Linux `auth.log`/`audit.log` tailer — `agent.py`'s `log_paths` is DNS-query-line extraction only, Windows has dedicated Security/Sysmon/PowerShell-log collectors but Linux has no analogous auth/audit collector; (2) no `--host-id` override for simulating multiple lab hosts from one machine — `HOST_ID` is derived from real machine identity with no CLI override. 191/191 endpoint-agent tests pass (unchanged — `agent.py` itself wasn't touched); packaging smoke-tested end-to-end for both platforms including running the packaged `agent.py --once`.
- **Integrity phase:** Packages now contain schema-versioned exact-file SHA-256 manifests, detached manifest checksums, and a fail-closed verifier that rejects corruption, missing/unexpected files, traversal, case collisions, and symlinks. Windows verifies, copies only listed files into an empty `%ProgramFiles%` destination, re-verifies, then creates the service. Linux verifies before user/file/systemd mutation and installs root-controlled files with bounded permissions. Host tests built and extracted both ZIPs; a stubbed Windows service install passed; a disposable Linux container install passed; 133 script tests passed (one Windows symlink test skipped for OS privilege and remains active on Linux CI).
- **Remaining scope:** Publisher-authenticated external signatures and validation on clean Windows/Linux VMs without a Python runtime. The detached SHA-256 proves consistency, not publisher authenticity; this environment still has neither a code-signing setup nor spare clean VMs.
