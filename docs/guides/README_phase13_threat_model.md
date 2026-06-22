# Phase 13 - Security of the Security System (Threat Model 1-Page)

## Scope & Assets
- **Assets**: security events, alerts, model artifacts, detector thresholds, allowlist, dashboard access, deployment metadata.
- **Trust boundaries**:
  - App/producer -> stream (Redpanda/Pandaproxy)
  - stream -> detector consumer
  - detector -> Postgres/ClickHouse/Grafana
  - operator -> config changes (threshold/allowlist/model deploy)

## Threats & Controls

| Threat | Impact | Current Control | Residual Risk | Next Hardening |
|---|---|---|---|---|
| Producer/consumer spoofing (unauthorized publish/consume) | fake telemetry, missed detection | Credentials and endpoints are env-driven (no hardcode), deployment lock for model, audit trail for config/model changes | Local mode still permissive by default | Enable Redpanda auth (SASL users), rotate creds, least-privileged consumer group ACL |
| Internal eavesdropping/tampering | event confidentiality/integrity loss | HMAC hashing for sensitive fields, event schema contract validation | transport plaintext in local stack | Enable TLS/mTLS between producer-broker-consumer and DB endpoints |
| Secret leakage (hardcoded key/password) | compromise pipeline/control plane | secrets moved to `.env`, scripts read DSN/env vars, no hardcoded DB creds in code | `.env` local exposure | use secret manager (Vault/K8s secret), periodic rotation policy |
| Dashboard account abuse | unauthorized data access | Grafana admin credentials from env, no anonymous mode in setup narrative, role separation supported by Grafana/Laravel auth | single admin account risk | enforce RBAC roles (viewer/editor/admin), SSO/OIDC, strong password policy |
| Silent model swap | stealth degradation/backdoor model | `ml_model_deployments` + artifact SHA lock in realtime consumer (`require-lock`) | manual ops error | signed artifact verification + approval workflow |
| Threshold/allowlist tampering | detector blind spots / FP masking | `security_audit_trails` records `THRESHOLD_UPDATED` and `ALLOWLIST_UPDATED` with actor + before/after | no approval gate yet | 2-person approval, immutable audit export |
| Drift unmonitored | model quality decay | PSI drift monitor + `DRIFT_DETECTED` alert + retrain policy checker | feature coverage still minimal | add more features, rolling baseline, auto ticketing |

## Implemented Technical Controls (This Phase)
- Audit trail table: `security_audit_trails`.
- Model registry/deployment: `ml_models`, `ml_model_deployments`.
- Deployment lock in consumer: reject startup on artifact hash mismatch.
- Drift detection: `scripts/mlops_drift_monitor.py` (PSI) -> alert `DRIFT_DETECTED`.
- Audited config change tools:
  - `scripts/update_detector_thresholds.py`
  - `scripts/update_detector_allowlist.py`

## Evidence Commands
- Register/deploy model:
  - `python scripts/mlops_register_model.py --deploy --env local --deployed-by thesis`
- Drift monitor:
  - `python scripts/mlops_drift_monitor.py --env local --lookback-hours 24`
- Retrain policy:
  - `python scripts/mlops_retrain_policy.py --env local`
- Audit check:
  - query `security_audit_trails` for `MODEL_DEPLOYED`, `THRESHOLD_UPDATED`, `ALLOWLIST_UPDATED`.

## Answer for Audience (Production Validity)
- **Confidentiality**: sensitive values hashed + secret via env.
- **Integrity**: schema contract validation + artifact hash lock + audited changes.
- **Availability/operations**: drift monitoring + retrain trigger policy.
- **Governance**: deploy/config changes are attributable (who, what, before/after, when).
