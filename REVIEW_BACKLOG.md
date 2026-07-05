# Pending Hardening & Cleanup Tasks (Backlog)

This file tracks all pending security hardening, refactoring, and documentation alignment tasks.
It is synchronized with GitHub Issues. Developers/writing agents (e.g., Claude) should resolve these tasks.

---

## Open Backlog Tasks

| Task ID | Title | File / Component | Priority | Status |
|---|---|---|---|---|
| **IDENTITY-SSO-MFA** | [Enterprise-XDR] No enterprise SSO (SAML/OIDC) or MFA for analyst authentication — session/password only | `app/Http/Controllers/Auth/*`, `config/auth.php`, `routes/auth.php` | High | Proposed |
| **OBS-OTEL-TRACING** | [Enterprise-XDR] No standards-based distributed tracing across polyglot services (OpenTelemetry / W3C traceparent) | `services/*/main.*`, `app/Http/Middleware/*`, ingestion→normalizer→correlation→alert-writer→incident-builder | Medium | Proposed |
| **ML-SERVE-ONLINE** | [Enterprise-XDR] Trained multiclass LR model is offline-script-only; not deployed as an online inference service in the live detection path | `scripts/train_ai_detector.py`, `scripts/realtime_detector_consumer.py`, `services/correlation-worker/main.go` | Medium-High | Proposed |
| **SECRETS-VAULT** | [Enterprise-XDR] No centralized secrets manager (Vault/KMS); all service/DB/HMAC secrets resolved from `.env`/env vars | `config/*`, `docker-compose*.yml`, `services/*`, `app/Services/InternalAuthService.php` | Medium | Proposed |
| **TECH-EOL-UPGRADE** | [Tech Currency] PHP `^8.1` (security EOL 2025-12), Laravel `^10.10` (EOL), Sanctum `^3.3` — running on end-of-life runtime/framework is not enterprise-supportable | `composer.json` | High | Proposed |
| **CODE-STRUCT-DECOMPOSE** | [Structure/Maintainability] `correlation-worker/main.go` is 2944 lines in one file (normalizer 1181, alert-writer 1277) — no package decomposition; hurts testability at enterprise scale | `services/correlation-worker/main.go`, `services/normalizer-worker/main.go`, `services/alert-writer-service/main.py` | Medium | Proposed |
| **CONNECTOR-FRAMEWORK** | [Capability — MOST ABSENT] No generic log-ingestion/connector framework — no syslog receiver, no CEF/LEEF parser, no cloud-native log connectors (CloudTrail/GuardDuty/O365). The "X" breadth of XDR is missing; ingestion is only the signed HMAC gateway + a few hand-coded typed normalizers | `services/ingestion-gateway`, `services/normalizer-worker`, new `services/log-connector-*` | High | Proposed |
| **ASSET-INVENTORY** | [Capability — ABSENT] No asset inventory / CMDB / asset-criticality tagging. Enterprise XDR needs asset context for risk-based prioritization (advisory-only; explicitly invited in REVIEW_REJECTED §AI-CONF-BANDS) | new `asset_inventory` tables, `app/Services/*`, enrichment on alerts | High | Proposed |
| **SIEM-SEARCH** | [Capability — ABSENT] No free-form/full-text search over raw telemetry; only bounded allowlisted hunt queries. Analysts cannot investigate arbitrary raw events (Splunk/Kibana-style search) | `app/Http/Controllers/*`, OpenSearch/ClickHouse query layer | Medium-High | Proposed |
| **DATA-TIERING** | [Capability — ABSENT] No tiered long-term searchable log storage (hot/warm/cold, archival to object storage). Only a 30/90-day prune exists — no retention beyond that, no cold tier | `app/Console/Commands/SecurityRetentionCommand.php`, ClickHouse, object storage | Medium | Proposed |
| **META-MODULE-RATIONALIZE** | [Off-track / Scope creep] ~32 of 90 services are self-referential readiness/certification/maturity/evidence-freeze/soak-sim modules (incl. 4× StabilityEvidenceFreeze, overlapping soak services) — huge maintenance surface, not XDR capability | `app/Services/*Readiness*.php`, `*Certification*.php`, `*EvidenceFreeze*.php`, `*Soak*.php`, `*Maturity*.php` | Medium | Proposed |
| **SIM-LAYER-REALITY-GATE** | [Dummy → must be real/labelled] HA/scale/chaos/soak/pilot validators emit PASS/readiness records without exercising a real cluster (LIMITATIONS admits "advisory/simulation layer, not tested under real distributed load") — a PASS can be misread as real validation | `app/Services/EnterpriseScaleHaService.php`, `TelemetryScalePilotService.php`, `SoakChaosValidationService.php`, `PilotExecutionService.php` | High | Proposed |
| **IG-HMAC-REPLAY** | [Security] Ingest HMAC signs body only — no timestamp/nonce → captured batches replayable forever. Sign `ts+body` with tolerance window behind compat flag | `services/ingestion-gateway/main.go`, `services/endpoint-agent/agent.py` | Medium | Proposed |
| **IG-HMAC-FAIL-OPEN** | [Security posture] Empty `XDR_INGEST_SECRET` disables signature check with WARN only; in enforced/prod posture gateway must refuse to start on empty/dev-default secret | `services/ingestion-gateway/main.go` | Medium | Proposed |
| **GO-BASEIMAGE-EOL** | [Tech currency] `go 1.22` + `golang:1.22-alpine` (EOL Feb 2025) and `alpine:3.20` (EOL 2026-04) in all 3 Go services — no security patches. Bump to supported Go ≥1.24 + alpine 3.22+ (complements TECH-EOL-UPGRADE which is PHP-only) | `services/{ingestion-gateway,normalizer-worker,correlation-worker}/{Dockerfile,go.mod}` | Medium | Proposed |
| **IB-DLQ-NOT-DURABLE** | [Reliability] Incident-builder failures live only in in-memory ring (lost on restart) — alert-writer has durable `xdr.alert_write_failed`, incident-builder has no `xdr.incident_write_failed` equivalent; asymmetric with unified DLQ review | `services/incident-builder-service/main.py`, `scripts/xdr_topic_bootstrap.py` | Medium | Proposed |
| **CONSUMER-GROUP-EPHEMERAL** | [Scalability] Fresh ms-timestamp consumer group + `earliest` on every start/recovery → full topic history reprocessed each restart; use stable group + offset commits, recreate identity only on offset_out_of_range | `services/alert-writer-service/main.py`, `services/incident-builder-service/main.py` | Medium | Proposed |
| **AIRAG-STUB-CITATIONS** | [Groundedness] `/v1/retrieve` returns 2 hardcoded fake citations with no stub label — mark `provider:"stub"` / `grounded:false` or return empty until Qdrant wired (adjacent to AI-1/RAG-1/RAG-2) | `services/ai-rag-service/main.py` | Low | Proposed |
| **PY-PRINT-LOGGING** | [Best practice] Python services log via `print()` — no levels/timestamps/structure; adopt stdlib `logging` + JSON formatter | `services/*/main.py` | Low | Proposed |
| **GO-GRACEFUL-SHUTDOWN** | [Reliability] SIGTERM handler calls `server.Close()` (drops in-flight ingest) while logging "gracefully"; use `server.Shutdown(ctx)` with drain timeout | `services/ingestion-gateway/main.go`, `services/correlation-worker/main.go` | Low | Proposed |






> **This file tracks only pending/open tasks.** Completed tasks live in `REVIEW_COMPLETED.md`; rejected/deferred in `REVIEW_REJECTED.md`. The running list of completions (NOTIFY-TENANCY-GAP, the PERF-* batches, IOC-HITS-IDEMPOTENCY, AGENT-SECRET-DECRYPT-500, RESP-POLICY-FAIL-OPEN, RATE-LIMIT-DOS, PERF-GO-HOT-HTTP, ENV-CACHE-DRIFT-BATCH, …) is in `REVIEW_COMPLETED.md`.
>
> **Classified out (2026-06-29):** `EDR-EXEC-02` and `AI-CONF-BANDS` → REJECTED (forbidden: automated active containment). `TENANT-ENFORCE-RLS` → DEFERRED (gated by RLS_DECISION_RECORD + GAP-002/003). See REVIEW_REJECTED.md.
>
> **Deferred — enterprise roadmap, staged (2026-07-01):** hot-path Go (`PERF-GO-LIMITER`, `PERF-GO-OVERCONCURRENT`), core-pipeline rearchitecture (`PERF-REST-POLL`, `PERF-REST-REBALANCE`, `ARCH-KAFKA-NATIVE`, `ARCH-DB-SPLIT`), infra (`ARCH-MTLS-SEC`, `ARCH-DISCOVERY`), AI live-model (`AI-KB-SEMANTIC`, `AI-KB-FEED-INGEST`) → in-scope for enterprise but each needs a dedicated validated effort. See REVIEW_REJECTED.md §2 (BATCH-DEFER-2026-07-01).

---

# Enterprise Full-XDR Gap Review (2026-07-04)

Code-level gaps found by auditing the running system against a **full enterprise XDR**
target. These are **new** — verified absent from `REVIEW_ALL.md`, `REVIEW_REJECTED.md`,
`REVIEW_COMPLETED.md`, and `REVIEW_FUTURE_BACKLOG.md`. None crosses a CLAUDE.md Forbidden
Change (no active containment, no autonomous response, shadow-soak gates still apply).
Each still needs Claude validation before implementation per the workflow.

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

## Proposed Task: SECRETS-VAULT — Centralized secrets manager instead of `.env`/env-var secrets

- **Priority:** Medium
- **Component:** `config/*`, `docker-compose*.yml`, `app/Services/InternalAuthService.php`,
  `services/*`
- **Finding:** All secrets — DB credentials, `XDR_INTERNAL_AUTH_SECRET`, per-service internal
  tokens, agent HMAC secrets, LLM API keys — resolve from `.env`/environment variables. No
  integration with a secrets manager (HashiCorp Vault, AWS Secrets Manager, K8s
  sealed-secrets); grep for `vault|kms|secretsmanager` finds nothing. INFRA-2 (done) only
  moved *hardcoded compose* secrets into `.env`; it did not add a vault, and there is no
  rotation mechanism for internal service tokens.
- **Why enterprise-relevant:** Plain env-var secrets with no rotation/audit are below
  enterprise bar (SOC 2 CC6). Related to ARCH-MTLS-SEC (deferred) but distinct: mTLS covers
  transport identity; this covers secret storage/rotation/lifecycle.
- **Proposed fix:** Add a pluggable secret-provider abstraction (env driver default for demo;
  Vault/KMS driver behind `XDR_SECRET_BACKEND`), route `InternalAuthService` and service-token
  reads through it, and add a `security:rotate-internal-token` command + rotation runbook.
  Keep the env driver default so the demo is unchanged.
- **Safety:** Config/security hardening only; no forbidden boundary touched.

## ✅ COMPLETED (2026-07-04): AUTH-TIMING-CMP — Non-constant-time internal-token compare in Python services

> Done — `verify_internal_token()` in both Python services now uses `hmac.compare_digest`; +10 tests. See REVIEW_COMPLETED.md.

- **Priority:** Medium (Security — timing side-channel)
- **Component:** `services/alert-writer-service/main.py` (`verify_internal_token`, lines ~1244/1247),
  `services/incident-builder-service/main.py` (`verify_internal_token`, lines ~598/601)
- **Finding — verified in code:** Both Python microservices validate the
  `X-Internal-Service-Token` with a plain Python `==` string comparison
  (`return token == expected`). Python `==` on `str` short-circuits on the first differing
  byte, leaking token-prefix-match length via response timing. This is **inconsistent with
  the rest of the codebase**, which already does this correctly:
  `app/Services/InternalAuthService.php` uses `hash_equals()` (lines 74, 110) and
  `services/ingestion-gateway/main.go` uses `hmac.Equal()` (line 489). Only the two Python
  services regressed to a timing-vulnerable compare.
- **Why enterprise-relevant:** These tokens gate `/v1/write`, `/v1/process`, and `/dlq`
  (which returns alert/incident evidence). A timing oracle on a privileged internal token is
  a real weakness once the pipeline ports are reachable beyond loopback (which is exactly the
  multi-node enterprise target). Low effort, high consistency payoff.
- **Proposed fix:** Replace `token == expected` with
  `hmac.compare_digest(token.encode(), expected.encode())` in both `verify_internal_token`
  functions (import `hmac`). Keep the permissive/enforced branching unchanged. Add a unit
  test asserting rejection of a wrong token of equal length.
- **Safety:** Pure security hardening; no behavior change for valid tokens; no forbidden boundary.

## ✅ COMPLETED (2026-07-05): MEM-UNBOUNDED-STATE — Bound the `SEEN` idempotency set and in-memory `DLQ` lists

> Done — `SEEN` is now a bounded LRU (`OrderedDict` + `_seen_add`, cap `XDR_ALERT_WRITER_SEEN_MAX=100000`); `DLQ` is a fixed-size `deque(maxlen=…)` ring in both services (`XDR_ALERT_WRITER_DLQ_MAX` / `XDR_INCIDENT_BUILDER_DLQ_MAX`, default 1000). `DLQ[-20:]` view fixed to `list(DLQ)[-20:]`. +6 tests. See REVIEW_COMPLETED.md.

- **Priority:** Medium (Reliability — memory growth / OOM at scale)
- **Component:** `services/alert-writer-service/main.py` (`SEEN` set line ~99, `DLQ` list line ~100),
  `services/incident-builder-service/main.py` (`DLQ` list line ~73)
- **Finding — verified in code:** The alert-writer keeps a process-global
  `SEEN: set[str]` of every alert fingerprint it has ever seen, added to on every write and
  **never evicted or capped** (`idempotency_cache_size` just reports `len(SEEN)`). Likewise
  both Python services keep `DLQ: List[Dict] = []` that is appended to on every failure and
  **never trimmed** — only the *view* endpoint slices `DLQ[-20:]`, while the backing list and
  `METRICS["dlq_count"] = len(DLQ)` grow without bound. Under sustained enterprise-volume
  ingestion (or a sustained failure storm) both structures grow until the container OOMs.
  Notably the endpoint agent already solves exactly this with
  `collections.deque(maxlen=STREAM_QUEUE_MAX)` (`agent.py:795`) — the bounded-buffer pattern
  exists in the repo but was not applied to these services.
- **Why enterprise-relevant:** Unbounded in-process state is a classic single-node demo
  artifact that fails under real 24/7 throughput. Idempotency dedupe should be TTL/LRU-bounded
  (or delegated to the DB unique constraint, which already exists via `ON CONFLICT`), and the
  DLQ ring should be a fixed-size buffer.
- **Proposed fix:** (1) Convert `DLQ` to `collections.deque(maxlen=N)` (e.g. 1000) in both
  services so `dlq_count` reflects a bounded ring; (2) bound `SEEN` with an LRU/TTL structure
  (e.g. `OrderedDict` capped at N, or time-windowed) — the DB `ON CONFLICT (alert_id)` /
  `(fingerprint)` upserts already guarantee correctness, so `SEEN` is a fast-path optimization
  and is safe to bound. Add a test that inserting > N items keeps memory bounded.
- **Safety:** Reliability hardening; DB idempotency guarantees preserved; append-only tables
  untouched; no forbidden boundary.

---

## Architecture / Infra / Tech-Currency review (2026-07-04)

Found by reading `composer.json`, `go.mod`, the Dockerfiles, and both compose overlays.
**Positives confirmed (not gaps):** `docker-compose.prod.yml` is well-hardened (per-service
`deploy.resources.limits`, `ports: !reset`, RO Grafana mounts, `XDR_ENFORCE_INTERNAL_AUTH:true`);
Go Dockerfiles are multi-stage + non-root (`adduser`/`USER app`); base compose has 9 healthchecks;
compiled `.exe`/`.pyc` are gitignored (not tracked). The items below are the real gaps.

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
  gate (4544 tests) green before merge. Laravel 11 changes the bootstrap/config structure, so
  do it as a dedicated effort, not bundled.
- **Safety:** Framework upgrade only; no product-boundary change. Must keep the full suite green.

## ✅ COMPLETED (2026-07-05): FASTAPI-LIFESPAN — Replace deprecated `@app.on_event` with lifespan handlers

> Done — both services now use `@contextlib.asynccontextmanager lifespan(...)` passed as `FastAPI(lifespan=…)`; the old `startup`/`shutdown` bodies became plain `_startup_tasks()`/`_shutdown_tasks()`. Behavior-neutral. +6 tests. See REVIEW_COMPLETED.md.

- **Priority:** Low (obsolete API / future-break)
- **Component:** `services/alert-writer-service/main.py` (lines 1250, 1275),
  `services/incident-builder-service/main.py` (lines 604, 611)
- **Finding — verified:** Both services use `@app.on_event("startup")` /
  `@app.on_event("shutdown")` to launch consumer threads and set the `STOP` event. FastAPI
  deprecated `on_event` in 0.93 (2023) in favor of a `lifespan` async context manager; it emits
  `DeprecationWarning` today and will be removed, silently breaking consumer startup/shutdown on
  a future `fastapi`/`uvicorn` bump (and `requirements.txt` pins only `fastapi>=0.110`, so an
  unattended `pip install` can pull that breaking release).
- **Proposed fix:** Move the startup thread-launch and shutdown `STOP.set()` into a
  `@contextlib.asynccontextmanager` lifespan passed as `FastAPI(lifespan=…)`. Behavior-neutral.
- **Safety:** Pure modernization; consumer semantics unchanged.

## ✅ COMPLETED (2026-07-05): PY-CONTAINER-HARDENING — Python Dockerfiles run as root, no healthcheck, unpinned deps

> Done — all 3 Python Dockerfiles now build a non-root `app` user (`groupadd`/`useradd` + `COPY --chown` + `USER app`) and a `HEALTHCHECK` hitting `/health` via stdlib `urllib` (no extra curl dependency). `requirements.txt` in all 3 services pinned to exact versions (`==`, freezing current resolution) instead of open `>=` ranges. Verified by installing the exact pins into a disposable venv and importing each service's real (non-stubbed) `main.py` + running its real `/health`/`/metrics` through the full FastAPI lifespan — confirms the earlier FASTAPI-LIFESPAN change also works against real (non-stub) FastAPI. **Deferred, documented limitation:** full hash-locking (`pip-compile --generate-hashes`) needs a Linux-matching build environment to verify; the Docker daemon was unavailable in this session (`docker version` connects to the client but not the engine), so only `docker compose config --quiet` (exit 0) could be run, not an actual image build. See REVIEW_COMPLETED.md.

## Proposed Task: PY-CONTAINER-HARDENING — Python Dockerfiles run as root, no healthcheck, unpinned deps

- **Priority:** Medium
- **Component:** `services/alert-writer-service/Dockerfile`,
  `services/incident-builder-service/Dockerfile`, `services/ai-rag-service/Dockerfile`,
  the three `requirements.txt`
- **Finding — verified:** The Python service Dockerfiles are single-stage `python:3.12-slim`
  that `pip install` then run `uvicorn` **as root** — no `USER` directive, no `HEALTHCHECK`, and
  `requirements.txt` uses unpinned lower bounds (`fastapi>=0.110`, `uvicorn[standard]>=0.27`,
  etc.) with no lockfile/hashes. That means (a) container processes run as UID 0 (violates
  least-privilege / breaks `runAsNonRoot` admission in K8s), (b) no container-level liveness
  signal, and (c) non-reproducible builds — two builds a week apart can ship different
  transitive dependency trees. The **Go** Dockerfiles already do this right (multi-stage,
  `adduser -D -H app` + `USER app`), so this is an inconsistency, not an unknown pattern.
- **Why enterprise-relevant:** Non-root containers, pinned/hash-locked dependencies, and
  healthchecks are standard enterprise container-supply-chain controls.
- **Proposed fix:** Add a non-root user + `USER` to each Python Dockerfile, add a `HEALTHCHECK`
  hitting `/health`, and pin dependencies (compile `requirements.txt` to fully-pinned,
  hash-locked versions via `pip-tools`/`uv`). Keep `python:3.12-slim` base.
- **Safety:** Infra hardening only; no application behavior change.

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

---

## Largest capability absences for enterprise FULL XDR (2026-07-04)

Answering "what is *most* missing/absent for a full enterprise XDR." These are whole
capabilities, verified absent in code (not weak — absent), ranked by impact. Enrichment is
**not** listed because it already exists offline (`GeoAsnLookupService` + `AlertAttributionService`).
All four are advisory/ingestion/read-only — none crosses a Forbidden Change.

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

## Proposed Task: ASSET-INVENTORY — Asset inventory / CMDB + advisory asset-criticality context

- **Priority:** High
- **Component:** new `asset_inventory` / `asset_criticality` tables, an `AssetContextService`,
  advisory enrichment on alerts/incidents
- **Finding — verified:** No asset inventory exists — grep for
  `asset_inventory|cmdb|asset_criticality|business_criticality|crown_jewel` returns nothing.
  The platform tracks endpoint *agents* but has no catalog of assets, their owners, business
  criticality, or environment (prod/dev). Risk scoring therefore has no asset context.
  `REVIEW_REJECTED.md` (§AI-CONF-BANDS) explicitly says an asset-criticality tag "purely as
  **advisory** alert-enrichment metadata (no response coupling)… would be a separate, new
  advisory-only proposal" — this is that proposal.
- **Why enterprise-relevant:** Risk-based alert prioritization ("this beacon is on a domain
  controller vs a test VM") is core to enterprise triage and is impossible without asset
  context. Also underpins crown-jewel monitoring and blast-radius scoring.
- **Proposed fix:** Add append-only-friendly `asset_inventory` (hostname/ip/owner/environment)
  and `asset_criticality` (advisory tier) tables + import command (CSV/CMDB sync), and enrich
  alerts/incidents with an advisory `asset_criticality` field used only to *rank* the analyst
  queue — never to trigger response (respects the no-autonomous-response boundary).
- **Safety:** Advisory metadata + prioritization only; explicitly decoupled from response
  execution. No forbidden boundary.

## Proposed Task: SIEM-SEARCH — Free-form search over raw telemetry for analysts

- **Priority:** Medium-High
- **Component:** new search controller + query layer over OpenSearch/ClickHouse, SOC UI view
- **Finding — verified:** There is no free-form/full-text search over raw events. Grep for
  `raw search|full text|log search` in controllers returns nothing. Threat hunting exists but is
  **bounded, allowlisted, field-equality queries** across defined domains — deliberately
  constrained. Analysts have no Splunk/Kibana-style ad-hoc search over raw telemetry to pivot
  during an investigation.
- **Why enterprise-relevant:** Interactive search over raw logs is a baseline SOC-analyst
  workflow; without it, investigation depth is capped by the pre-defined hunt domains.
- **Proposed fix:** Add a read-only search surface over the existing OpenSearch alert index and
  (optionally) a ClickHouse raw-event store, with tenant scoping enforced via
  `TenantContextAuthority`, RBAC (`soc:search.view`), bounded result windows, and the existing
  `TraceRedactor` applied to output. Read-only — no mutation, respects append-only guarantees.
- **Safety:** Read-only query surface; tenant-scoped + redacted; no data mutation.

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

---

## Off-track / scope-creep / dummy-vs-real review (2026-07-04)

Answering "what is irrelevant / off-track / simulated-but-should-be-real." **First, what is
legitimately NOT a defect (verified, so the backlog stays honest):** the 121 shadow detection
rules are the *deliberate* strangler posture (promotion gated by GAP-001 domain soaks) — shadow
≠ dummy; the threat-intel service is **real** (`ExternalThreatIntelService::virusTotalLike`/
`abuseIpDbLike` make live VirusTotal/AbuseIPDB HTTP calls when keys are set, `importFeed` parses
MISP/OpenCTI); notification delivery is **real** (`SocNotifier` uses `Http::post`); the
response simulation-first flow is a **safety design**, not theater. The two real problems:

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

## Proposed Task: SIM-LAYER-REALITY-GATE — Back (or clearly label) the HA/scale/chaos/soak simulators

- **Priority:** High (credibility / enterprise validity)
- **Component:** `EnterpriseScaleHaService`, `TelemetryScalePilotService`, `SoakChaosValidationService`,
  `PilotExecutionService`, `EnterpriseOperationsAutomationService`, related "validation run" tables
- **Finding — verified:** These services accept parameters (e.g. `node_count`, endpoint counts,
  chaos scenario) and **write a `*_validation_run` record with a PASS/score** — without exercising
  any real distributed infrastructure. `docs/thesis/LIMITATIONS_FUTURE_WORK.md` states it plainly:
  *"HA governance, multi-tenant isolation, and cluster topology are implemented at the advisory/
  simulation layer, not tested under real distributed load."* Redpanda is single-node (GAP-004),
  so an `EnterpriseScaleHaService` "HA_PASS" is a computed record, not a failover proof. The risk:
  a stored `PASS` / high readiness score is indistinguishable, in the UI/exports, from a real
  validated result.
- **Why enterprise-relevant:** "Full enterprise XDR" claims must be backed by real evidence.
  Either the load-bearing validators (HA failover, scale, soak) run against a real multi-node
  cluster, or every simulated result must be unambiguously non-authoritative.
- **Proposed fix (two-track):** (a) **Label:** add an explicit `is_simulated=true` +
  `evidence_basis='computed'|'measured'` field (and UI/export badge) to every such run so a
  simulated PASS can never be read as measured — cheap, immediate, preserves academic honesty.
  (b) **Back the key ones:** wire `EnterpriseScaleHaService`/soak to the real `docker-compose.ha.yml`
  multi-node path (ties into GAP-004 / HA-DRILL-01) so at least HA-failover and soak produce
  *measured* evidence before any production-readiness claim. Track (b) as the enterprise gate.
- **Safety:** Governance/labelling + real validation harness; advisory-only records preserved;
  no autonomous action; append-only tables untouched.








---

---

# Claude QC Pipeline Audit (2026-07-05)

Findings from a code-level QA/QC + architecture audit by Claude (source: `REVIEW_ALL.md` — Review Batch 18,
CLAUDE-QC-PIPELINE-AUDIT). Full issue/fix detail lives in Batch 18; headers below exist so the standard
workflow (validate → GitHub Issue → `## Task #N`) applies. Verified non-duplicate against
REVIEW_ALL / REVIEW_REJECTED / REVIEW_COMPLETED. None crosses a CLAUDE.md Forbidden Change.

## ✅ COMPLETED (2026-07-05): PIPE-CONSUMER-AUTH-500 — Consumer loop breaks when internal token is configured (Critical)

> Done — extracted `_write_alerts_core(request)` / `_build_incidents_core(request)` (no `x_internal_service_token` parameter at all) out of the `write()`/`build()` HTTP routes; the routes now check `verify_internal_token()` then delegate to the core function, and `process_alerts()` (called by both `event_loop()`s) calls the core function directly instead of the route function. **Bug reproduced and fix verified against real (non-stubbed) FastAPI/pydantic** in a disposable venv: calling `write()`/`build()` directly under `XDR_ENFORCE_INTERNAL_AUTH=true` + a configured token raised `AttributeError: 'Header' object has no attribute 'encode'` (the real defect — worse than a clean 401) on both services; after the fix, `process_alerts()` under the identical enforced+token posture returns `ok=True` on both. +8 unit tests (route delegates to core, core has no token param, core runs successfully under enforced mode, route source still contains the auth check). See REVIEW_COMPLETED.md.

- **Priority:** Critical (Bug)
- Event loop calls FastAPI route functions directly; `Header(default=None)` leaks in → 401/AttributeError on every
  batch when `XDR_*_INTERNAL_TOKEN` is set (exactly the `docker-compose.prod.yml` posture) → endless consumer
  recreate, zero alerts written. Fix: extract `_write_alerts_core()` / `_build_incidents_core()`; auth stays at HTTP layer.
  Regression test: event loop with token env set must still write. See REVIEW_ALL Batch 18.

## ✅ COMPLETED (2026-07-05): AW-DEDUPE-BEFORE-COMMIT — SEEN cache poisons replay after failed PG write (High)

> Done — `_write_alerts_core()` now computes fingerprints into a local `seen_in_batch` set (still deduping intra-batch repeats) and only commits them into the durable `SEEN` LRU via `_seen_add()` *after* `write_postgres()` succeeds (`postgres_ok`), or in pure dry-run (no backing store configured at all, where marking on receipt is the intended demo behavior). A failed Postgres write no longer poisons `SEEN`. Verified against real pydantic: an alert that fails to write, then is retried once the store recovers, is written successfully instead of being silently dropped as a duplicate. +4 unit tests + 1 real-dependency reproduction. See REVIEW_COMPLETED.md.

`_seen_add(fp)` runs before `write_postgres()`; a replayable `postgres_write_failed` batch is later dropped as
duplicate — silent alert loss. Fix: record fingerprints only after successful write. See REVIEW_ALL Batch 18.

## Proposed Task: IG-HMAC-REPLAY — Add timestamp/nonce to ingest HMAC (Medium)
Signature covers body only; captured batches replay forever. Sign `ts + "." + body`, tolerance window,
compat flag `XDR_INGEST_SIGV2_REQUIRED=false` default. See REVIEW_ALL Batch 18.

## Proposed Task: IG-HMAC-FAIL-OPEN — Fail-fast on empty/dev ingest secret in enforced posture (Medium)
Empty `XDR_INGEST_SECRET` silently disables auth (WARN only). When `XDR_ENFORCE_INTERNAL_AUTH=true`,
refuse to start on empty or `dev-secret-change-me`. See REVIEW_ALL Batch 18.

## Proposed Task: GO-BASEIMAGE-EOL — Upgrade Go 1.22 / alpine:3.20 (both EOL) (Medium)
All 3 Go services build on EOL toolchain+runtime images. Bump go.mod ≥1.24, `golang:*-alpine` builder,
`alpine:3.22+` runtime. Stdlib-only services → low-risk. Complements TECH-EOL-UPGRADE (PHP). See REVIEW_ALL Batch 18.

## Proposed Task: IB-DLQ-NOT-DURABLE — Durable failure topic for incident-builder (Medium)
Incident write/publish failures exist only in the in-memory ring (lost on restart). Add
`write_incident_failure()` → `xdr.incident_write_failed`, register in topic bootstrap, extend pipeline-DLQ
classification. See REVIEW_ALL Batch 18.

## ✅ COMPLETED (2026-07-05): PY-POISON-RECORD-BATCH — Per-record poison isolation in Python consumers (Medium)

> Done — `AlertPayload(**row)` construction in `normalize_records()` (both services) is now wrapped in a per-record `try/except`; a malformed record is isolated to the bounded `DLQ` ring as `alert_payload_invalid: …` and the loop continues, instead of the exception propagating up through `event_loop()` and aborting the entire poll batch (previously: a single poison record forced a recreate-from-earliest loop forever). Verified against real pydantic with a mixed valid/invalid batch: the malformed record is isolated to DLQ and the valid record in the same batch is still recovered. +6 unit tests (3/service) + 1 real-dependency reproduction. See REVIEW_COMPLETED.md.

One malformed record aborts the whole poll batch → recreate-from-earliest loop forever. Per-record
try/except → skip + structured DLQ entry (mirror normalizer 40801 pattern). See REVIEW_ALL Batch 18.

## Proposed Task: CONSUMER-GROUP-EPHEMERAL — Stable consumer group + offset commits (Medium)
Fresh ms-timestamp group + `earliest` on every start/recovery reprocesses full topic history each restart.
Stable group id + explicit commits; recreate identity only on offset_out_of_range. Enterprise-relevant
reliability — per classification rules must not be Rejected. See REVIEW_ALL Batch 18.

## Proposed Task: AIRAG-STUB-CITATIONS — Label fabricated /v1/retrieve results as stub (Low)
Hardcoded fake citations returned with no stub marker. Add `provider:"stub"` / `grounded:false` or return
empty until Qdrant retrieval is wired. See REVIEW_ALL Batch 18.

## Proposed Task: PY-PRINT-LOGGING — Replace print() with structured logging (Low)
Adopt stdlib `logging` + shared JSON-line formatter across Python services; map `[SECURITY-WARN]`/WARN
prefixes to levels. See REVIEW_ALL Batch 18.

## Proposed Task: GO-GRACEFUL-SHUTDOWN — Use server.Shutdown(ctx) on SIGTERM (Low)
`server.Close()` drops in-flight ingest requests during deploys despite "gracefully" log line. Drain with
10–15 s Shutdown timeout. See REVIEW_ALL Batch 18.
