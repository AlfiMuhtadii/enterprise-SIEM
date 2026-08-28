# Pending Hardening & Cleanup Tasks (Backlog)

This file tracks all pending security hardening, refactoring, and documentation alignment tasks.
It is synchronized with GitHub Issues. Developers/writing agents (e.g., Claude) should resolve these tasks.

---

### Open Backlog Tasks

### Tabel A: Tugas Pengembangan Lokal & Simulasi (Nol Modal / Gratis)

| Task ID | Title | File / Component | Priority | Status |
|---|---|---|---|---|
| **ENT-TENANCY-NO-DB-ENFORCEMENT** | [Enterprise BLOCKER] Isolation is app-layer `where('tenant_id')` only (ASSET-TENANT-OVERWRITE proves it leaks); no DB RLS. Supersedes deferred TENANT-ENFORCE-RLS — mandatory for multi-tenant SaaS | `app/Services/TenantBoundaryService.php`, Postgres RLS | High | Proposed (re-open TENANT-ENFORCE-RLS) |
| **CODE-STRUCT-DECOMPOSE** (correlation-worker, normalizer-worker, alert-writer-service, incident-builder-service, ThreatHuntingService, ReportExportService, UEBABaselineService, EntityRiskScoringService decomposed; Pandaproxy/Kafka transport intentionally remains) | See detail section below | see detail section | Medium | Proposed (reduced) |
| **ARCH-DB-SPLIT** (write path + soak tooling + live verification done 2026-07-12; 5 of 7+ read paths migrated and live-verified 2026-07-14 — dashboard domain breakdown, single-host endpoint timeline, threat hunt search, forensic collection, detection backtest; only the 2 correlation detectors remain, deliberately excluded) | [Enterprise scale — promoted 2026-07-06] Both `telemetry_events` write paths (Python batch/stream ingesters + `AgentIngestionController::telemetry()`) now route to a new ClickHouse `telemetry_events` table (with `tenant_id` from day one) behind `XDR_TELEMETRY_WRITE_TARGET` (default `postgres`, zero behavior change). `scripts/load_test_soc.py` gained a real concurrent write-throughput soak generator (`--ingest-target`/`--ingest-concurrency`). Ran this for real against Docker-backed infra: found and fixed a genuine bug (ClickHouse's default `DateTime64` parser rejects plain ISO-8601 timestamps — `date_time_input_format=best_effort` added to the HTTP query URL), then captured real measured evidence — ClickHouse ~29-54% higher write throughput than Postgres across concurrency levels, and a real dashboard-style aggregation query against Postgres slowed 3.8x on average (9.4x at p95) under a concurrent 16-worker telemetry write soak. Read-path phase 1 (2026-07-14): `App\Services\ClickHouseTelemetryReader` migrated the 2 lowest-risk read sites — dashboard domain breakdown and single-host endpoint timeline. Read-path phase 2 (2026-07-14): 3 more methods added — `huntSearch()` (mirrors `SocHuntController`'s multi-filter free-text search, including the `payload ILIKE` domain search — ClickHouse's `payload` is a plain `String` column so this needs no `::text` cast the way Postgres's does), `forensicHostEvents()` (mirrors `SocForensicController`'s exact-host_id forensic bundle lookup), and `identityCloudSaasWindow()` (mirrors `DetectionBacktestService`'s identity/cloud/saas replay window — safe to migrate despite reading the same telemetry_type range the 2 excluded correlation detectors also read, since this service only ever writes to the advisory-only `detection_backtest_runs`/`detection_backtest_matches` tables, never to `security_alerts`/`security_incidents`). All 5 read sites fall back to Postgres on any ClickHouse failure so none of them ever just break. `TENANT-CLICKHOUSE-LEAK` (2026-07-14, see `REVIEW_COMPLETED.md`) closed the ClickHouse side of tenant scoping for 4 of these 5 methods (all but the global/admin-only `identityCloudSaasWindow`) — the Postgres fallback still has no tenant_id column on telemetry_events at all (`TenantBoundaryService::UNISOLATED_TABLES`), a separate, larger, structural gap belonging with `ENT-TENANCY-NO-DB-ENFORCEMENT`. Only the 2 correlation detectors (`scripts/xdr_correlation_detector.py`'s `detect_identity()`/`detect_cloud_saas()`) remain explicitly out of scope — a silent output change there is a correctness incident, not a dashboard/hunt/forensics page showing slightly stale data | `app/Services/ClickHouseTelemetryReader.php`, `app/Http/Controllers/SocDashboardController.php`, `app/Http/Controllers/SocEndpointTimelineController.php`, `app/Http/Controllers/SocHuntController.php`, `app/Http/Controllers/SocForensicController.php`, `app/Services/DetectionBacktestService.php`, `scripts/ingest_telemetry_events.py`, `scripts/telemetry_stream_worker.py`, `scripts/xdr_infra_clients.py`, `scripts/load_test_soc.py`, `app/Services/ClickHouseTelemetryWriter.php`, `app/Http/Controllers/AgentIngestionController.php` | High | Proposed (reduced) |
| **INTERNAL-RUNTIME-SDK** (phases 1/2/3 done — mTLS + delivery-retry Go drift guardrails, Python event-contract/tracing/OTLP/pool/Kafka adapter dedup; only full cross-module extraction, blocked by Docker build-context, remains) | Consolidate duplicated mTLS, delivery, event-contract, tracing, pool, and Kafka helpers into reviewed internal packages. See detail section below | Go/Python service shared runtime helpers | Medium | Proposed (reduced) |
| **PERF-REDACTION-OVERHEAD** | [Performance] Optimize double serialization and redundant regex overhead in trace redaction. | `app/Support/TraceRedactor.php`, `app/Services/SiemSearchService.php` | Medium | Completed (implementation `7753629`; benchmark verified Codex, 2026-08-26) |

### Tabel B: Tugas Infrastruktur Produksi & Layanan Cloud (Memerlukan Modal / Cloud)

| Task ID | Title | File / Component | Priority | Status |
|---|---|---|---|---|
| **ENT-SEC-NO-TLS-INTERNAL** (phases 1-11 done — service TLS scaffolding, Pandaproxy/OpenSearch TLS, downstream Python CA verification, and native Kafka TLS clients/listeners are implemented; Postgres full mTLS plus remaining PHP wiring remain) | [Enterprise BLOCKER — reduced] Phase 10 added opt-in, fail-closed native Kafka TLS clients to all three Go pipeline services. Phase 11 added additive Redpanda listeners on container `9093` and loopback-only host `19093`, read-only CA mounts, explicit plaintext-compatible defaults, and a 20-check semantic Compose gate in CI. Real generated-CA produce/consume passed through both `127.0.0.1:19093` (38 ms) and `redpanda:9093` (31 ms); an untrusted CA failed. Live service testing exposed and fixed Pandaproxy/native group protocol collisions by deriving isolated `<group>-native` IDs; normalizer and correlation then held one stable native member each with no reconnect. Plaintext `9092/19092` remained operational. Remaining scope is PostgreSQL server/client mTLS, Laravel/PHP CA wiring, and other plaintext infrastructure clients. | `infra/redpanda/redpanda.yaml`, `docker-compose.yml`, `.env*.example`, `services/{ingestion-gateway,normalizer-worker,correlation-worker}`, `tools/shared-go/kafkanative`, `scripts/xdr_kafka_tls_compose_validate.py`, `config/database.php`, `app/Services/InternalAuthService.php` | High | Proposed (reduced; native Kafka TLS listener phase completed by Codex, 2026-08-28) |
| **ENT-REL-SIMULATED-HA** | [Enterprise BLOCKER] HA/scale/DR "PASS" is computed, not measured on real cluster (SIM-LAYER Track B + HA-DRILL-01). "Too heavy for laptop" invalid at enterprise bar — run on real staging before any availability claim | `app/Services/EnterpriseScaleHaService.php` et al., `docker-compose.ha.yml` | High | Proposed (re-open Track B + HA-DRILL-01) |
| **ENT-SDLC-NO-SUPPLYCHAIN** (base-image digest pinning, SBOM, dependency scan, container scan, signed release publication, deployment verifier, and release vulnerability gate implemented) | [Enterprise SDLC - reduced] Every canonical release digest is scanned before signing under `release-critical-v1`: `CRITICAL` findings (fixed or unfixed), mutable references, and scanner/report/auth failures block; `HIGH` remains retained advisory evidence. The scanner now copies only inline registry-scoped credentials into a permission-restricted ephemeral config and strips host-only credential helpers before mounting it read-only. Live Docker proof: an immutable public registry digest passed remote policy, while `detector-ci-app:latest` was blocked with exit 2 for 16 CRITICAL findings. Every per-image workflow report is retained for 90 days. Remaining scope is a real private-GHCR signed-release/live deployment run; no staging target currently exists. | `.github/workflows/release.yml`, `scripts/xdr_container_image_scan.py`, `scripts/xdr_release_{manifest,verify}.py`, `docs/operations/{RELEASE_VULNERABILITY_POLICY,IMMUTABLE_RELEASE_VERIFICATION}.md` | Medium | Proposed (reduced; credential-helper remediation live-verified by Codex, 2026-08-27) |
| **IDENTITY-SSO-MFA** (TOTP MFA + mandatory enforcement + OIDC SSO federation + SAML SSO federation all done) | [Enterprise BLOCKER — re-ranked] Per-user opt-in TOTP now implemented (`TotpService`, RFC 6238, dependency-free — verified against the RFC's own published test vector, not just self-consistency). Login gates on a 6-digit code when a user has enabled it; existing password-only login is unaffected for everyone else. Mandatory MFA enforcement now wired (`EnsureMfaVerified`/`mfa.required` middleware, gated by `SOC_MFA_ENFORCEMENT_ENABLED`, default `false`) on response-plan/active-response/data-erasure approve routes. OIDC authorization-code SSO federation now implemented (`OidcSsoService`/`OidcSsoController`, gated by `SOC_OIDC_SSO_ENABLED`, default `false`) — real RS256 ID-token signature verification via `firebase/php-jwt` + JWKS. SAML 2.0 SP-initiated SSO federation now also implemented (`SamlSsoService`/`SamlSsoController`, gated by `SOC_SAML_SSO_ENABLED`, default `false`) — real XML-DSig assertion-signature verification + XSD schema validation via `onelogin/php-saml` (the other place, alongside OIDC's `firebase/php-jwt`, this codebase's usual "dependency-free protocol implementation" convention was deliberately not followed — hand-rolling XML signature verification is a well-known vulnerability source, XML signature wrapping specifically). Both federation paths never auto-provision accounts — only an existing user matched by a verified/signed identity claim can sign in; per-user TOTP is still enforced after SSO login either way. Verified against real local mock IdPs (genuine ephemeral keypairs, real signed/verified tokens/assertions) since no live external IdP exists in this environment. Still missing: end-to-end verification against a real corporate IdP (Okta/Azure AD/ADFS) for either protocol — needs a real external IdP this environment doesn't have; `/sso/saml/metadata` is the concrete handoff point for that future pass | `app/Services/TotpService.php`, `app/Services/OidcSsoService.php`, `app/Services/SamlSsoService.php`, `app/Http/Controllers/Auth/MfaController.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Controllers/Auth/OidcSsoController.php`, `app/Http/Controllers/Auth/SamlSsoController.php`, `app/Http/Middleware/EnsureMfaVerified.php`, `config/oidc.php`, `config/saml.php`, `routes/auth.php`, `routes/web.php` | High | Proposed (reduced) |
| **DATA-TIERING** (phases 1/2/2b/3 done — archive-then-prune, searchable local archive, RBAC-gated UI, real ClickHouse warm tier; cold tier remains) | See detail section below | `app/Services/SecurityRetentionArchiveService.php`, `app/Services/ArchiveSearchService.php`, `app/Services/ClickHouseArchiveWriter.php`, `app/Services/ClickHouseArchiveSearchService.php` | Medium | Proposed (reduced) |
| **SIM-LAYER-REALITY-GATE** (Track B only — Track A done) | [Dummy → must be real] Track A (labelling) done: all 35 HA/scale/chaos/soak/pilot validation-run tables now carry `is_simulated`/`evidence_basis`. Remaining: Track B — back the key validators (HA failover, scale, soak) against a real multi-node harness (`docker-compose.ha.yml`) so they produce *measured*, not just *computed*, evidence | `app/Services/EnterpriseScaleHaService.php`, `TelemetryScalePilotService.php`, `SoakChaosValidationService.php`, `PilotExecutionService.php`, `docker-compose.ha.yml` | High | Proposed (reduced) |
| **ARCH-DISCOVERY** | [Enterprise infra — promoted 2026-07-06] Static hostnames only; multi-node needs DNS/service discovery + internal LB. Belongs with a real multi-node deploy (ties ENT-REL-SIMULATED-HA / HA-DRILL-01) | `docker-compose*.yml`, deploy manifests | Medium | Proposed (staged — infra) |

> **This file tracks only pending/open tasks.** Completed tasks live in `REVIEW_COMPLETED.md`; rejected/deferred in `REVIEW_REJECTED.md`.
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

## Proposed Task: CICD-MERGE-GATE - Restore Green CI and Protect the Default Branch

- **Status:** Completed (Codex, 2026-08-28) - commit `ed57739` fixed the normalizer Kafka multi-record fetch test flake; remote runs `33150262874` (`ci`, including `Required Gate`), `33150262868` (`phase9-contract`), and `33150262867` (`security-scan`) passed. Branch protection remains active.
- **Priority:** High
- **Component:** `.github/workflows/*.yml`, GitHub branch protection/rulesets
- **Finding:** Clean GitHub runners exposed repository-state dependencies hidden by the local dirty worktree: missing Python service dependencies, missing canonical Kafka adapter copies, an unversioned Trivy scanner and rule pack, mutable rule storage assumed to exist, non-portable Compose image lookup, stale fixtures, PHPStan baseline drift, and race-unsafe Go tests.
- **Implemented:** Commits `9f70994`, `02d2dc6`, `41bab27`, `32ab2ff`, and `1c9a8c7` restore deterministic Phase 9 replay, race-enabled Go gates, complete Python suites, baseline-aware PHP static analysis, current Compose/image validation, and clean-checkout Laravel behavior. Remote runs `32856500030` (`ci`) and `32856499748` (`phase9-contract`) passed on `1c9a8c7`; security run `32856499816` also passed.
- **Protection:** `master` now requires an up-to-date PR plus GitHub Actions checks `Required Gate` and `contract-and-replay` (both bound to app ID `15368`), conversation resolution, and admin enforcement. Force pushes and branch deletion are disabled. This personal repository has no separate audited break-glass role, so no bypass allowance is configured; emergency access requires an explicit protection change.
- **Validation Gate:** Green feature-branch runs prove both required contexts exist and pass; the protection API read-back confirms PR/status-check/admin enforcement. No intentionally failing PR or real direct-push probe was created because both would add avoidable remote mutation; GitHub enforces those controls from the verified protection configuration.

## Proposed Task: CICD-POLYGLOT-COVERAGE - Align CI with the Current Runtime and Validation Contract

- **Status:** Completed (Codex, 2026-08-28) - commit `cd509b8` pins all 47 remote workflow actions to immutable SHAs, upgrades required actions to verified Node 24 releases, and enforces the policy in governance with 5 validator tests. Remote runs `33160184104` (`ci`, including `Required Gate`), `33160184188` (`phase9-contract`), and `33160184213` (`security-scan`) passed without the Node 20 deprecation warning.
- **Priority:** High
- **Component:** `.github/workflows/ci.yml`, `docs/ci/validation-pipeline.md`, all Go/Python service suites, current Compose files
- **Finding:** CI tests PHP and frontend only, compiles Python only under `scripts/`, runs no Go checks or Python service/endpoint-agent suites, and builds the legacy 4-service `infra/production/docker-compose.production.yml` instead of the current polyglot stack. Documented merge gates and `AGENTS.md` commands are not represented in the workflow.
- **Proposed Fix:** Split CI into PHP/PostgreSQL, Go matrix, Python matrix, frontend, contract/governance, current Compose, and first-party image-build jobs. Run `migrate:fresh --force` before the non-parallel PHP suite, validate `docker-compose.yml` + `docker-compose.prod.yml`, and upload validator reports even on failure.
- **Validation Gate:** Every first-party service has a required build/test job; changing any Go/Python service triggers its suite; the resolved production Compose topology is validated; documentation and workflow commands match exactly.

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


## Proposed Task: INTERNAL-RUNTIME-SDK - Consolidate Duplicated Security and Transport Helpers

- **Priority:** Medium
- **Component:** Go mTLS/delivery/bounded-file packages; Python event-contract/tracing/OTLP/PostgreSQL/Kafka helpers; service Dockerfiles and tests
- **Status (2026-07-16):** Phases 1/2/3 done (see `REVIEW_COMPLETED.md`) — all 3 confirmed-byte-identical duplicated families now have a canonical source plus the same drift-check guardrail (`scripts/xdr_shared_go_package_drift_validate.py`, generalized to a `FAMILIES` dict so it isn't Go-only despite the filename): **mtls** (`tools/shared-go/mtls/`, 8 Go services), **deliver** (`tools/shared-go/deliver/`, 5 log-connectors), and **python-service-adapters** (`tools/shared-python/service-adapters/` — `xdr_event_contracts.py`/`traceparent.py`/`otlp_export.py`/`pg_pool.py`/`kafka_native.py`, `alert-writer-service` + `incident-builder-service`). Validator: `status=PASS` for all 3 families, 0 drifted, 0 missing. Real cross-module extraction (shared Go module + `replace` directives, or a real installable Python package) was evaluated and rejected for every family: every service's Docker build context is its own directory, so a dependency pointing outside it breaks the Docker build — fixing that needs widening every Dockerfile's build context to the repo root, a separate, larger, riskier change.
- **Remaining scope:** Only full cross-module extraction, structurally blocked by the Docker build-context constraint above — not a "not yet done," an accepted architectural tradeoff documented in `docs/ci/SHARED_GO_PACKAGES.md`. CI wiring of the drift validator into `ci.yml` is also deferred, pending the concurrent CI restructure landing.
- **Validation Gate:** Consumers use pinned internal package versions or one reviewed workspace revision; cross-service contract tests cover certificate validation, retries, event envelopes, Kafka commit semantics, and tracing; duplicate copies are removed only after parity is proven. — met for all 3 families via the drift validator's byte-for-byte comparison, which is stricter than a contract test for pure-copy duplication (no functional divergence is even possible, since any diff fails the check).

---

# Best-Practice Audit — language / stack / infra / architecture / accessibility / security (2026-07-14)

Cross-dimension sweep by Claude against enterprise/international best practice. Verified
non-duplicate against REVIEW_ALL / REVIEW_COMPLETED / REVIEW_REJECTED (grep: zero prior mentions of
a11y/WCAG/i18n/CSP/CodeQL/dependabot/phpstan). Evidence gathered from the live codebase. What was
found ALREADY GOOD (no task needed): `html lang` is locale-dynamic; agent API already has
`throttle:api`; frontend stack (Tailwind 3.4 / Alpine 3.14 / Vite 5.4 / axios 1.7) is on supported,
non-EOL versions; test DB isolation + parallel is now correct; the Go event pipeline versions its
envelopes. All 6 gaps found (A11Y-WCAG, I18N-LOCALIZATION, SEC-HTTP-HEADERS, CI-SAST-DEPSCAN,
QA-STATIC-ANALYSIS, API-VERSIONING) are now done as bounded first phases — see `REVIEW_COMPLETED.md`.

## Completed Task: PERF-REDACTION-OVERHEAD — Eliminate Double Serialization and Optimise Regex Scans in Redactor

- **Status:** Completed (implementation commit `7753629`; benchmark verified Codex, 2026-08-26)
- **Priority:** Medium
- **Component:** `app/Support/TraceRedactor.php`, `app/Services/SiemSearchService.php`
- **Finding:** `SiemSearchService` converted decoded OpenSearch arrays back to JSON strings so `TraceRedactor` could decode them again, while email regex execution ran for strings that could not contain an email address.
- **Implemented Fix:** `TraceRedactor` traverses decoded arrays/objects directly, `SiemSearchService` passes OpenSearch sources without a serialization round trip, and strings without `@` bypass the email regex.
- **Validation Gate:** PASS via `php scripts/xdr_trace_redactor_benchmark.php --output=reports/xdr_trace_redactor_benchmark.json`. Seven isolated-process iterations over 500 synthetic maximum-search-window records (six 16 KiB payload fields per row; 49,152,000 payload bytes/run) produced equivalent redacted output. Median CPU fell from 109.375 ms to 31.250 ms (71.43% reduction), wall time from 104.767 ms to 27.213 ms (74.03%), and peak redaction heap from 258,248 bytes to 8,480 bytes (96.72%) on PHP 8.4.24. The benchmark exits non-zero when either CPU or heap reduction is below 50%.
