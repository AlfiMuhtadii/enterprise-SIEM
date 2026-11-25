# Domain Soak Readiness (ENTERPRISE-060)

This advisory report reviews operational prerequisites. It does not start or
schedule a live soak, verify representative telemetry, or authorize promotion.
`REAL_EXECUTION_GATED=true`; `PROMOTION_RECOMMENDED=false`. Active correlation
remains limited to `identity-cloud`.

## Readiness Status

| Status | Meaning |
|---|---|
| `BLOCKED` | At least one required gate failed. |
| `PARTIAL` | No required gate failed, but a diagnostic warning remains. |
| `DIAGNOSTIC_READY` | All reported gates passed; execution is still not authorized. |
| `UNVERIFIED` | Historical plan recorded before this policy; its verdict is not current evidence. |

## Diagnostic Phases

| Phase | What the plan checks | Read-only diagnostic |
|---|---|---|
| 1. Staged-active baselines | Fixture-backed confidence, baseline prerequisites, and Go configuration | `php artisan xdr:strangler-status` |
| 2. Shadow sources | Advisory diagnostic prerequisites and verified representative source evidence for endpoint, network, and UEBA | `php artisan domain:soak-preflight endpoint` |
| 3. Tier-1 fixture coverage | Fixture service, recorded results, and confidence metadata | `php artisan rule:run-fixtures --tier=tier_1_immediate --dry-run` |
| 4. Endpoint next batch | Actual diagnostic outcomes from phases 1-3 and registry coverage | `php artisan domain:soak-preflight endpoint` |

Phase 2 fails closed if any domain has no verified representative source
evidence. A passing advisory preflight, replay fixture, or simulator result
does not qualify. The current preflight does not verify promotion evidence;
Phase 2 and its dependent Phase 4 therefore remain blocked.

The existing correlation benchmark generates load. It cannot stand in for a
domain-specific six-hour live soak, and the plan does not give commands to run
it as one. Before any separate promotion review, the domain must have verified
representative telemetry and a recorded six-hour live soak PASS. No gate in
this report changes the active allowlist or routes endpoint alerts to the active
topic.

## Validation Commands

```powershell
php artisan soak:plan-review --dry-run
php artisan soak:plan-review --phase=2 --dry-run
php artisan domain:soak-preflight endpoint
php artisan domain:soak-preflight network
php artisan domain:soak-preflight ueba
python scripts/xdr_soak_execution_plan_validate.py
```

Use `--dry-run` to review without saving a plan. Without it, the planner
appends an advisory snapshot; it still does not start a soak.
