# Evaluator Walkthrough Guide

> All demonstrations are synthetic, replay-safe, advisory-only, and bounded.
> No destructive exploitation or autonomous remediation is executed.

---

## Estimated Total Time: 20–30 minutes

---

## Step 1 — Start the Platform (2 minutes)

```powershell
.\bootstrap-dev.ps1
```

Or manually:
```bash
docker compose up -d && php artisan migrate:fresh --force && php artisan db:seed --class=DemoScenarioSeeder && php artisan serve
```

**Expected:** All services healthy, demo fixtures loaded, Laravel accessible at `http://localhost:8000`.

---

## Step 2 — Platform Readiness Check (2 minutes)

Navigate to: `http://localhost:8000/demo-platform/readiness`

**What to observe:**
- PHP tests: 4545 passed
- Python tests: 1556 passed (all suites)
- Rule registry: PASS (133 rules, 21/21 checks)
- Contract validation: PASS
- Resilience: 8/8 PASS
- Fleet simulation: 8/8 PASS

**Expected output:** `overall_ready: true`

---

## Step 3 — Attack Chain Walkthrough (5 minutes)

Navigate to: `http://localhost:8000/demo-platform/scenarios`

Launch scenario: **phishing_attack_chain**

**What to observe:**
1. Phishing email → script execution event chain loaded
2. Advisory alerts generated (ENCODED_COMMAND_EXECUTION, BROWSER_CHILD_PROCESS_ANOMALY)
3. Attack stage timeline populated
4. Entity graph: user → process → network

Navigate to: `http://localhost:8000/demo-platform/timeline`

**Expected:** Completed run with event_count > 0, is_lab_safe=true, is_destructive=false

---

## Step 4 — Threat Hunting Pivot (5 minutes)

Navigate to: `http://localhost:8000/threat-hunts`

Create a new hunt on domain: `processes`
Query: `process_name contains "powershell"`

**What to observe:**
- Allowlisted field validation (no arbitrary SQL)
- Bounded result set (MAX_RESULTS=500)
- Advisory-only hunt record created (append-only)

---

## Step 5 — Detection Quality Scorecard (3 minutes)

Navigate to: `http://localhost:8000/xdr-maturity/detection`

**What to observe:**
- Deterministic precision/recall scoring
- ATT&CK coverage scores
- Evidence completeness metrics
- All scores bounded [0.0, 1.0]

---

## Step 6 — Governance Overview (5 minutes)

Navigate to: `http://localhost:8000/xdr-certification`

**What to observe:**
- Final XDR Certification dashboard
- Production acceptance gates
- SELF_APPROVE_BLOCKED enforcement
- Advisory-only, no autonomous go-live

Navigate to: `http://localhost:8000/release-stabilization`

**What to observe:**
- Feature freeze governance
- RC_PASS_THRESHOLD = 0.85 enforcement
- MAX_PILOT_ENDPOINTS = 20 enforcement

---

## Step 7 — Capability Matrix (2 minutes)

Navigate to: `http://localhost:8000/demo-platform/capabilities`

**What to observe:**
- Honest capability matrix: implemented / advisory_only / not_implemented
- Clear separation of active vs shadow scope
- Intentionally not implemented items explicitly listed (kernel EDR, live containment)

---

## Step 8 — Architecture Explorer (2 minutes)

Navigate to: `http://localhost:8000/demo-platform/architecture`

**What to observe:**
- Polyglot microservices architecture summary
- Active scope: identity/cloud/SaaS (6h soak PASS 2026-05-14)
- Shadow scope: endpoint, DNS/proxy/firewall, threat-intel

---

## Key Architectural Claims to Verify

| Claim | Verification |
|-------|-------------|
| Replay-safe event sourcing | `ON CONFLICT DO NOTHING` in all append-only tables |
| ACTIVE_ALLOWLIST = empty | `python scripts/xdr_rule_registry_validate.py` → PASS |
| No autonomous remediation | assertFalse(method_exists($svc, 'autoRemediate')) in all test files |
| Bounded operations | All services have MAX_* constants, checked in tests |
| 6h soak PASS | `reports/xdr_correlation_soak_6h.json` |

---

> This walkthrough reflects the current platform state as of 2026-05-24.
> All scenarios are deterministic and can be repeated indefinitely.
