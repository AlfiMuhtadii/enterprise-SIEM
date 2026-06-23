# Pending Hardening & Cleanup Tasks (Backlog)

This file tracks all pending security hardening, refactoring, and documentation alignment tasks.
It is synchronized with GitHub Issues. Developers/writing agents (e.g., Claude) should resolve these tasks.

---

## Task #19: [SCALE-026] Controlled load and soak validation script [Labels: area:ingestion, agent:claude, risk:low, type:implementation]
* **Target**: GitHub Issue [#19](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/19)
* **Goal**: Grounded implementation based on issue definition.

### Goal & Requirements:
> Source: User task BACKLOG-SCALE-026.

> Approved scope: scripts/xdr_scale_soak_validate.py (new), tests/xdr_topic_bootstrap/test_xdr_scale_soak_validate.py (new).

> Forbidden: No ACTIVE_ALLOWLIST changes, no shadow promotion, no changes to production services, no live Redpanda/PostgreSQL mutations in unit tests.

> Acceptance criteria:
> - Tiered load profiles (small: 50 eps, medium: 200 eps, large: 500 eps)
> - Validates throughput, latency p95, replay amplification bounds, and alert count delta per tier
> - MAX_REPLAY_AMP=3.0 guard applied
> - --profile flag selects tier
> - --dry-run mode for offline validation (no live infra required)
> - Exit 0=PASS, 1=FAIL, 2=ERROR
> - JSON report output
> - Unit tests cover dry-run path, tier configuration validation, bounds checking

> Validation:
> - python scripts/xdr_scale_soak_validate.py --dry-run
> - Full Python suite

---

## Task #16: [DB-5] Populate tenant_id in security_alerts and security_incidents write paths [Labels: agent:claude, area:tenant, risk:high, type:implementation]
* **Target**: GitHub Issue [#16](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/16)
* **Goal**: Grounded implementation based on issue definition.

### Goal & Requirements:
> Source: REVIEW_REPORTS.md Finding DB-5 / Gemini backlog proposal.

> Approved scope: services/alert-writer-service/main.py, services/incident-builder-service/main.py only.

> Forbidden: No schema changes (tenant_id column already exists on both tables via earlier migration), no ACTIVE_ALLOWLIST changes, no shadow promotion.

> Acceptance criteria:
> - AlertPayload in alert-writer-service extracts tenant_id from normalized event and writes it to security_alerts
> - incident-builder-service resolves tenant_id from alert and writes it to security_incidents
> - Alerts and incidents created with correct non-null tenant_id when telemetry carries tenant_id
> - Strict mode scoped queries return correctly scoped alerts/incidents
> - Relevant Python tests pass

> Validation:
> - python -m unittest discover -s tests/xdr_topic_bootstrap -p test_*.py (targeted)
> - Full Python suite

---

## Task #15: [CORR-1] Align telemetry type checks for identity_provider and saas_audit in correlation worker [Labels: area:ingestion, risk:medium, agent:claude, type:implementation]
* **Target**: GitHub Issue [#15](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/15)
* **Goal**: Grounded implementation based on issue definition.

### Goal & Requirements:
> Source: REVIEW_REPORTS.md Finding CORR-1 / Gemini backlog proposal.

> Approved scope: services/correlation-worker/main.go only.

> Forbidden: No ACTIVE_ALLOWLIST changes, no shadow→active promotion, no new rules added.

> Acceptance criteria:
> - Correlation rule filters accept both 'identity'/'identity_provider' and 'saas'/'saas_audit' string forms
> - OR normalizer is updated (in NW-1) to output canonical 'identity'/'saas' and correlation checks are not duplicated
> - Correlation worker correctly processes normalized events from identity and SaaS sources
> - Existing contract tests pass

> Validation:
> - python scripts/xdr_contract_validate.py
> - Full Python suite

---

## Task #14: [NW-1] Propagate tenant_id and demo lineage metadata in all normalizer type-specific helpers [Labels: area:ingestion, risk:medium, agent:claude, type:implementation]
* **Target**: GitHub Issue [#14](https://github.com/AlfiMuhtadii/enterprise-SIEM/issues/14)
* **Goal**: Grounded implementation based on issue definition.

### Goal & Requirements:
> Source: REVIEW_REPORTS.md Finding NW-1 / Gemini backlog proposal.

> Approved scope: services/normalizer-worker/main.go only.

> Forbidden: No schema changes, no topic changes, no ACTIVE_ALLOWLIST changes, no shadow promotion.

> Acceptance criteria:
> - normalizeEndpoint, normalizeDns, normalizeProxy, normalizeFirewall, normalizeSaas, normalizeIdentityProvider all map tenant_id, demo_run_id, source_event_id, scenario_id from raw input fields
> - Normalized events contain these fields when present in raw input
> - Normalizer starts up correctly
> - Existing tests continue to pass

> Validation:
> - python -m unittest discover -s tests/endpoint_agent -p test_*.py (targeted)
> - Full relevant Python suite

---
