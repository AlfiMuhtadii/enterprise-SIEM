# Security Hardening Evidence Freeze — ENTERPRISE-074

**Status:** Implemented  
**Date:** 2026-06-29  
**Posture:** Advisory-only — no enforcement changes

---

## Purpose

Consolidates evidence of all security hardening controls implemented across
ENTERPRISE-039 through the backlog hardening tasks (ENV-CACHE-DRIFT, CMD-SHARED-HMAC,
AGENT-TENANCY-GAP, TENANT-UNSCOPED-TABLES, RATE-LIMIT-BYPASS).

Produces an immutable, append-only record of the security posture at a given point
in time. Supports thesis defense, audit review, and pre-production gate validation.

---

## Controls Evaluated (10)

| Control ID | Category | What it checks |
|---|---|---|
| `config_cache_auth_secret` | config_security | `InternalAuthService` uses `config()` not `env()` |
| `internal_auth_secret_mapped` | config_security | `xdr.internal_auth_secret` key in `config/xdr.php` |
| `per_agent_hmac_secret` | endpoint_security | `endpoint_agents.hmac_secret` column present |
| `endpoint_fleet_tenant_isolation` | tenant_isolation | `endpoint_agents` in `ISOLATED_TABLES` with `tenant_id` |
| `workflow_tables_tenant_isolation` | tenant_isolation | `investigations`, `response_plans`, `entities` have `tenant_id` |
| `threat_hunts_append_only_isolated` | tenant_isolation | `threat_hunts` in `APPEND_ONLY_ISOLATED_TABLES` |
| `ingestion_tenant_header_validation` | ingestion_security | `extractPayloadTenantID()` + mismatch rejection in `main.go` |
| `rls_scaffold_present` | infrastructure | `scaffold_rls_policies` migration exists (advisory, no enforcement) |
| `container_resource_limits` | infrastructure | `deploy.resources.limits` in `docker-compose.yml` |
| `tenant_strict_mode_configured` | config_security | `xdr.tenancy.strict_mode` config key exists |

---

## How to Run

```powershell
# Generate a freeze snapshot
php artisan security:hardening-freeze

# With JSON output
php artisan security:hardening-freeze --output=reports/security_hardening_freeze.json

# Python offline validator (no database required)
python scripts/xdr_security_hardening_evidence_freeze.py
python scripts/xdr_security_hardening_evidence_freeze.py --output=reports/shf_evidence.json
```

---

## Tables (9 — all append-only)

| Table | Purpose |
|---|---|
| `security_hardening_freeze_runs` | Top-level freeze run record |
| `security_hardening_freeze_checks` | Per-control evaluation results |
| `security_hardening_freeze_control_evidence` | Evidence artifact links |
| `security_hardening_freeze_gate_snapshots` | Gate evaluation snapshots |
| `security_hardening_freeze_coverage_reports` | Coverage score reports |
| `security_hardening_freeze_remediation_guidance` | Advisory remediation notes |
| `security_hardening_freeze_certification_requests` | Analyst certification requests |
| `security_hardening_freeze_audit_events` | Audit trail |
| `security_hardening_freeze_delta_reports` | Delta from previous freeze |

---

## Advisory Constraints

- `ADVISORY_ONLY = true` — no enforcement changes
- `SELF_APPROVE_BLOCKED = true` — analyst cannot self-certify
- `autonomous_certification = false` — always false, never autonomous
- `MIN_PASS_SCORE = 0.85` — 85% of controls must pass to meet threshold
- All tables append-only — never UPDATE or DELETE rows

---

## SOC Views

| Route | View |
|---|---|
| `/security-hardening-freeze` | Dashboard — latest run summary |
| `/security-hardening-freeze/runs` | All freeze run history |
| `/security-hardening-freeze/controls` | Per-control check results |
| `/security-hardening-freeze/coverage` | Coverage report history |
| `/security-hardening-freeze/delta` | Delta reports between runs |

---

## Pass Criteria

- All 10 controls: PASS
- Coverage score: ≥ 85%
- Python validator: `12/12 checks PASS`
- PHP tests: 60+ tests green

---

## Related Tasks

- ENTERPRISE-039: RBAC Audit Coverage (self-approval guards)
- ENTERPRISE-040: RLS Decision Record
- ENTERPRISE-068: Container Resource Limits
- ENTERPRISE-069: RLS Policy Scaffolding
- ENV-CACHE-DRIFT: InternalAuthService config() migration
- CMD-SHARED-HMAC: Per-agent HMAC secret
- AGENT-TENANCY-GAP: Endpoint fleet tenant scoping
- TENANT-UNSCOPED-TABLES: Workflow table tenant isolation
- RATE-LIMIT-BYPASS: Ingestion tenant header validation
