# Quickstart Guide for Evaluators

**Estimated time:** 15–20 minutes to full demo walkthrough  
**Prerequisites:** Docker Desktop, PHP 8.2+, Python 3.10+, Composer

---

## Step 1 — Start the Platform (2 minutes)

```powershell
# Windows
.\bootstrap-dev.ps1

# Linux/macOS
chmod +x bootstrap-dev.sh && ./bootstrap-dev.sh
```

**Expected output:**
```
=== XDR Platform Bootstrap (Development) ===
Advisory only — no destructive execution, no real malware, no autonomous remediation.

[1/5] Checking Docker...
  Docker: OK
[2/5] Starting infrastructure services...
  Services: started
[3/5] Running fresh migration (DEV ONLY — drops all data)...
  Migration: OK
[4/5] Seeding demo fixtures...
  Seed: OK
[5/5] Running startup validation...
  Routes: OK

=== Bootstrap complete ===

  Platform URL:    http://localhost:8000
  Demo Dashboard:  http://localhost:8000/demo-platform
  Grafana:         http://localhost:3000
```

Then in a second terminal:

```powershell
php artisan serve
```

---

## Step 2 — Verify Platform Readiness (1 minute)

Navigate to: http://localhost:8000/demo-platform/readiness

**Expected:** `overall_ready = true`, PHP tests shown, rule registry shown green.

Alternatively, run the validation suite:

```powershell
php artisan test
# Expected: 3043 passed, 0 failures

python -m unittest discover -s tests/endpoint_agent -p "test_*.py" -v
# Expected: 186 tests, OK
```

---

## Step 3 — Demo Attack Chain (5 minutes)

Navigate to: http://localhost:8000/demo-platform/scenarios

1. Review the `phishing_attack_chain` scenario — observe the attack stages
2. Navigate to http://localhost:8000/demo-platform/timeline — view the attack timeline
3. Navigate to http://localhost:8000/threat-hunts — create a hunt on `processes` domain
4. Observe advisory-only results — no destructive output

---

## Step 4 — Detection Quality Review (3 minutes)

Navigate to: http://localhost:8000/xdr-maturity/detection

**Observe:**
- Precision/recall metrics per detection rule category
- FP/FN analysis (advisory-only)
- ATT&CK coverage mapping
- XDR maturity tier (5-tier: initial → optimizing)

---

## Step 5 — Governance Review (3 minutes)

Navigate to:
- http://localhost:8000/xdr-certification — XDR readiness certification, acceptance gates
- http://localhost:8000/soar — SOAR orchestration (simulation-first, dual-approval)
- http://localhost:8000/compliance — evidence integrity, tenant isolation
- http://localhost:8000/release-governance — RC manifests, go/no-go decisions

**Key point:** All response plans require simulation + dual analyst approval. No autonomous execution.

---

## Step 6 — Architecture & Capability Review (3 minutes)

Navigate to:
- http://localhost:8000/demo-platform/architecture — active vs shadow scope
- http://localhost:8000/demo-platform/capabilities — capability matrix with honest tiers
- http://localhost:8000/demo-platform/showcase — final platform showcase dashboard

---

## Step 7 — Replay & Export (2 minutes)

Navigate to:
- http://localhost:8000/demo-platform/replay — deterministic replay pipeline explorer
- http://localhost:8000/demo-platform/showcase — recent exports, platform stats

---

## Reset the Demo

```powershell
# Full reset — drops all data and re-seeds
.\bootstrap-dev.ps1

# Quick reset without infrastructure restart
php artisan migrate:fresh --force
php artisan db:seed --class=DemoScenarioSeeder
```

---

## What You Are Seeing

**All demonstrations are:**
- Synthetic — using deterministic fixture data
- Replay-safe — `ON CONFLICT DO NOTHING` throughout
- Advisory-only — no destructive execution
- Bounded — all loops and simulations have explicit MAX_* limits
- Non-destructive — no real malware, no live exploitation, no autonomous remediation

**The platform demonstrates:**
- A production-discipline strangler migration from a monolith SOC to a polyglot microservice pipeline
- Enterprise-grade governance across 50+ subsystems
- Hybrid rule-based + logistic regression detection
- 158-domain threat hunting with multi-hop graph investigation
- Full audit trail with append-only event sourcing

---

## Troubleshooting

| Problem | Solution |
|---|---|
| Docker not running | Start Docker Desktop, then re-run bootstrap |
| Port 8000 in use | `php artisan serve --port=8001` |
| Migration fails | Ensure PostgreSQL container is healthy: `docker compose ps` |
| Tests fail | Run `php artisan migrate:fresh --force && php artisan test` |
| Grafana not loading | Wait 30s for OpenSearch to initialize, then reload |
