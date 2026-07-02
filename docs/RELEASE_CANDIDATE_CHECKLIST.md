# Release Candidate Checklist — RC1

**Platform:** Hybrid Near Real-Time Web Attack Detection Platform  
**RC Version:** 1.0.0-rc1  
**Date:** 2026-05-24

Use this checklist before any presentation, defense, or portfolio review.

---

## Pre-Presentation Checklist

### Environment

- [ ] Docker Desktop is running
- [ ] `.\bootstrap-dev.ps1` completed successfully (exit 0)
- [ ] `php artisan serve` is running at localhost:8000
- [ ] Demo Dashboard loads: http://localhost:8000/demo-platform
- [ ] Readiness Dashboard shows `overall_ready = true`: http://localhost:8000/demo-platform/readiness

### Validation

- [ ] `php artisan migrate:fresh --force && php artisan test` → 4544 passed, 0 failures
- [ ] `python -m unittest discover -s tests/endpoint_agent -p "test_*.py" -v` → 186 passed
- [ ] `python scripts/xdr_rule_registry_validate.py` → PASS, rules=133, checks=21/21
- [ ] `python scripts/xdr_contract_validate.py` → PASS
- [ ] `python scripts/xdr_resilience_validate.py` → 8/8 passed
- [ ] `python scripts/xdr_fleet_simulation_validate.py` → 8/8 passed

### Documentation

- [ ] `README.md` is current and accurate
- [ ] `docs/RELEASE_NOTES.md` is accurate
- [ ] `docs/FINAL_CAPABILITY_MATRIX.md` is accurate
- [ ] `docs/KNOWN_LIMITATIONS.md` is honest and complete
- [ ] `docs/QUICKSTART_EVALUATOR.md` matches actual platform behavior

### Demo Scenarios

- [ ] `phishing_attack_chain` scenario available and seeded
- [ ] `lolbin_execution_chain` scenario available and seeded
- [ ] `noisy_enterprise_simulation` scenario available and seeded
- [ ] Fixture files exist: `fixtures/demo/scenarios/*.json`

### Architecture Claims

- [ ] Identity/cloud/SaaS correlation: staged_active ✓
- [ ] Endpoint/DNS/proxy/firewall: shadow-only ✓
- [ ] `XDR_CORRELATION_FALLBACK_TO_LEGACY=true` ✓
- [ ] `ACTIVE_ALLOWLIST` is empty for all shadow domains ✓
- [ ] No `execute_*` action types in `ALLOWED_TYPES` ✓
- [ ] `SELF_APPROVE_BLOCKED` enforced in certification service ✓

---

## Validation Command Reference

```powershell
# Full test suite (run this first)
php artisan migrate:fresh --force && php artisan test

# Python endpoint agent
python -m unittest discover -s tests/endpoint_agent -p "test_*.py" -v

# Rule registry
python scripts/xdr_rule_registry_validate.py

# Contract validation
python scripts/xdr_contract_validate.py --output reports/xdr_contract_validation.json

# Resilience
python scripts/xdr_resilience_validate.py --output reports/resilience/resilience-validation-report.json

# Fleet simulation
python scripts/xdr_fleet_simulation_validate.py

# Fault injection
python scripts/xdr_fault_injection.py --output reports/resilience/fault-injection-report.json

# Secret validation
php artisan security:validate-secrets
```

---

## Key URL Reference

| Feature | URL |
|---|---|
| Demo Dashboard | http://localhost:8000/demo-platform |
| Demo Scenarios | http://localhost:8000/demo-platform/scenarios |
| Attack Timeline | http://localhost:8000/demo-platform/timeline |
| Demo Readiness | http://localhost:8000/demo-platform/readiness |
| Architecture Explorer | http://localhost:8000/demo-platform/architecture |
| Capability Matrix | http://localhost:8000/demo-platform/capabilities |
| Replay Explorer | http://localhost:8000/demo-platform/replay |
| Walkthrough Console | http://localhost:8000/demo-platform/walkthrough |
| Showcase Dashboard | http://localhost:8000/demo-platform/showcase |
| XDR Maturity Dashboard | http://localhost:8000/xdr-maturity |
| Detection Scorecard | http://localhost:8000/xdr-maturity/detection |
| FP/FN Analysis | http://localhost:8000/xdr-maturity/fpfn |
| Telemetry Quality | http://localhost:8000/xdr-maturity/telemetry |
| XDR Readiness Report | http://localhost:8000/xdr-maturity/report |
| XDR Certification | http://localhost:8000/xdr-certification |
| Threat Hunts | http://localhost:8000/threat-hunts |
| Security Alerts | http://localhost:8000/security/alerts |
| Incidents | http://localhost:8000/incidents |
| SOAR | http://localhost:8000/soar |
| Grafana | http://localhost:3000 |

---

## Go/No-Go Summary

| Criterion | Status |
|---|---|
| All PHP tests pass | ✓ |
| All Python tests pass | ✓ |
| Rule registry PASS | ✓ |
| All validators PASS | ✓ |
| Documentation complete | ✓ |
| Demo scenarios seeded | ✓ |
| Architecture claims accurate | ✓ |
| Limitations documented | ✓ |
| Rollback capability preserved | ✓ |
| Advisory posture maintained | ✓ |

**Overall: GO for RC1 presentation**
