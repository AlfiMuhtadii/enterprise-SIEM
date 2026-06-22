# Hybrid Near Real-Time Web Attack Detection Platform

**Academic Title:** Hybrid Near Real-Time Web Attack Detection Platform using Rule-Based Detection and Multiclass Logistic Regression within an Event-Driven Investigation Architecture

A full-stack, enterprise-governed, AI-assisted XDR-like platform built as an academic research project. Demonstrates a production-discipline distributed architecture with a strangler-pattern migration from a monolith SOC to a polyglot microservice pipeline — entirely advisory-only, replay-safe, and deterministic.

---

## Platform Overview

| Dimension | Summary |
|---|---|
| Architecture | Polyglot microservices (PHP/Go/Python), event-driven, strangler migration |
| Detection | Rule-based + Multiclass Logistic Regression, 133 detection rules |
| Correlation | Identity/cloud/SaaS: active (6h soak PASS); endpoint/DNS/proxy: shadow-only |
| Threat Hunting | 158 query domains, advisory-only, append-only results |
| Governance | 50+ governance subsystems: HA, compliance, SOAR, release governance, XDR maturity |
| Test Coverage | 3077 PHP tests, 186 Python tests, all green |
| Validation | Rule registry 133/21 checks PASS; fleet sim 8/8; resilience 8/8 |

---

## Quick Start (Demo)

```powershell
# Windows — one-command demo bootstrap
.\bootstrap-dev.ps1

# Then start the Laravel server
php artisan serve
```

```bash
# Linux/macOS
chmod +x bootstrap-dev.sh && ./bootstrap-dev.sh
php artisan serve
```

Open http://localhost:8000 and navigate to:
- **Demo Dashboard** → http://localhost:8000/demo-platform
- **Threat Hunting** → http://localhost:8000/threat-hunts
- **XDR Maturity** → http://localhost:8000/xdr-maturity
- **Grafana** → http://localhost:3000

> **Advisory Only:** All demonstrations are synthetic, replay-safe, and bounded. No destructive exploitation or autonomous remediation is executed.

---

## Architecture

```
Telemetry Source
  → ingestion-gateway  (Go, HMAC-SHA256 signed)
  → telemetry.raw      (Redpanda)
  → normalizer-worker  (Go)
  → telemetry.normalized
  → correlation-worker (Go)
  → xdr.alerts              [identity/cloud/SaaS — ACTIVE]
  → xdr.alerts.shadow.*     [endpoint/DNS/proxy — SHADOW ONLY]
  → alert-writer-service    (Python/FastAPI)
  → security_alerts         (PostgreSQL)
  → incident-builder-service (Python/FastAPI)
  → security_incidents      (PostgreSQL)
  → Laravel SOC control-plane
```

### Services

| Service | Technology | Role |
|---|---|---|
| Laravel SOC | PHP/Blade | Control plane: RBAC, incidents, investigations, governance, threat hunting, XDR maturity |
| ingestion-gateway | Go | Signed telemetry ingestion, rate limiting, backpressure |
| normalizer-worker | Go | Raw → normalized telemetry |
| correlation-worker | Go | Identity/cloud/SaaS correlation (active) + endpoint shadow |
| alert-writer-service | Python/FastAPI | Alerts → PostgreSQL + OpenSearch |
| incident-builder-service | Python/FastAPI | Alerts → incidents |
| ai-rag-service | Python/FastAPI | Analyst assist, Qdrant vector store |
| endpoint-agent | Python stdlib | Lightweight behavioral endpoint visibility |

### Infrastructure

| Component | Role |
|---|---|
| Redpanda | Kafka-compatible event streaming |
| PostgreSQL | Primary SOC state |
| ClickHouse | Async analytics |
| OpenSearch | Alert indexing |
| Qdrant | Vector store for AI/RAG |
| Grafana | Observability dashboards |

---

## Detection Coverage

| Category | Rules | Status |
|---|---|---|
| Identity / Cloud / SaaS | 12 | **staged_active** — 6h soak PASS (2026-05-14) |
| Endpoint behavioral (shadow) | 32 | shadow-only |
| Low-level endpoint telemetry (shadow) | 8 | shadow-only |
| UEBA behavioral analytics (shadow) | 9 | shadow-only |
| Network: DNS/proxy/firewall (shadow) | 9 | shadow-only |
| Threat-intel/IOC (shadow) | 3 | shadow-only |
| Advanced: cred/persist/evasion/lateral (shadow) | 20 | shadow-only |
| Detection depth Phase 2 (shadow) | 40 | shadow-only |
| **Total** | **133** | |

> Shadow rules require domain-specific 6h soak PASS before promotion. `ACTIVE_ALLOWLIST` is intentionally empty for all shadow domains.

---

## Governance Subsystems

The platform implements 50+ governance subsystems across these domains:

- **Detection Engineering** — rule versioning, replay validation, FP/FN analysis, suppression governance
- **SOAR Orchestration** — simulation-first, dual-approval, blast-radius scoring, rollback-ready
- **HA / Distributed Reliability** — worker heartbeats, duplicate detection, degraded mode audit
- **Compliance & Evidence Integrity** — evidence hashing, PII audit, tenant isolation, export governance
- **Capacity & Performance Governance** — linear projection, replay economics, storage pressure
- **Release Governance** — deterministic manifests, go/no-go gates, rollback validation
- **XDR Maturity Scoring** — 5-tier maturity (initial → optimizing), code-level quality metrics
- **Pilot Readiness** — bounded onboarding, approval-gated, self-approve-blocked
- **Enterprise Scale HA** — cluster topology, failover coordination, HA validation
- **Commercial Readiness** — tenant onboarding, support bundles, deployment packaging

---

## Demo Scenarios

| Scenario | Description |
|---|---|
| `phishing_attack_chain` | Credential phishing → lateral movement → data staging |
| `lolbin_execution_chain` | Living-off-the-land binary abuse chain |
| `noisy_enterprise_simulation` | High-volume noisy enterprise telemetry simulation |
| `identity_compromise` | Identity provider compromise + SaaS escalation |
| `lateral_movement` | Host-to-host lateral movement via process ancestry |
| `persistence_install` | Registry/scheduled task persistence indicators |
| `data_exfiltration` | Beaconing + DNS tunneling simulation |
| `ransomware_precursor` | Encryption precursor behavioral indicators |

All scenarios: synthetic, replay-safe, lab-safe, no real malware, no autonomous remediation.

---

## Validation

```powershell
# Primary gate — run after every change
php artisan migrate:fresh --force && php artisan test

# Endpoint agent tests
python -m unittest discover -s tests/endpoint_agent -p "test_*.py" -v

# Rule registry
python scripts/xdr_rule_registry_validate.py

# Contract validation
python scripts/xdr_contract_validate.py

# Resilience validation
python scripts/xdr_resilience_validate.py

# Fleet simulation
python scripts/xdr_fleet_simulation_validate.py
```

**Current baselines:** 3077 PHP tests, 186 Python tests, 133 rules (21/21 checks PASS), resilience 8/8, fleet sim 8/8.

---

## Documentation

| Document | Path |
|---|---|
| Evaluator Quickstart | `docs/QUICKSTART_EVALUATOR.md` |
| Demo Walkthrough | `docs/DEMO_WALKTHROUGH.md` |
| Thesis Defense Guide | `docs/THESIS_DEFENSE_GUIDE.md` |
| Interview Showcase Guide | `docs/INTERVIEW_SHOWCASE_GUIDE.md` |
| Known Limitations | `docs/KNOWN_LIMITATIONS.md` |
| Future Roadmap | `docs/FUTURE_ROADMAP.md` |
| Release Notes | `docs/RELEASE_NOTES.md` |
| Release Candidate Summary | `docs/RELEASE_CANDIDATE_SUMMARY.md` |
| Capability Matrix | `docs/FINAL_CAPABILITY_MATRIX.md` |
| Feature Registry | `docs/architecture/FEATURE_REGISTRY.md` |
| Architecture Changelog | `docs/architecture/ARCHITECTURE_CHANGELOG.md` |
| Operational Posture | `docs/operations/OPERATIONAL_POSTURE.md` |
| Validation Baselines | `docs/validation/VALIDATION_BASELINES.md` |
| Thesis Positioning | `docs/thesis/THESIS_POSITIONING.md` |
| Defense Preparation | `docs/thesis/DEFENSE_PREPARATION.md` |

---

## What This Platform Is NOT

This is an academic research platform, not a commercial product. It does not implement:

- Kernel EDR / kernel telemetry
- Live host containment or isolation
- Malware prevention or blocking
- Offensive automation
- Hyperscale commercial SIEM replacement
- Autonomous SOC operations

All detections are advisory-only. All response plans are simulation-first and approval-gated.

---

## Operational State

```env
XDR_CORRELATION_ENGINE=go
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
```

Active alert domains: identity, cloud, SaaS  
Shadow/advisory domains: endpoint, DNS, proxy, firewall, threat-intel  
Rollback capability: preserved (circuit breaker: 3 consecutive failures → legacy fallback)
