# Endpoint Fleet Simulation Guide

## Purpose

This guide describes how to run deterministic fleet-scale simulations to validate that the endpoint fleet hardening system detects and reports degraded states correctly under controlled failure conditions.

Simulations are **non-destructive, advisory-only, and replay-safe**. They exercise the same agent helper functions used in production without touching any live database, network, or endpoint.

---

## When to Use Simulations

| Trigger | Command |
|---|---|
| After modifying `agent.py` spool or tamper logic | `python scripts/xdr_fleet_simulation_validate.py` |
| After modifying `EndpointFleetService.php` | `php artisan test --filter="EndpointFleetSimulationTest"` |
| Before any fleet-related deployment | Both commands above |
| CI gate for fleet hardening changes | Both commands above |

---

## Running the Python Validator

```powershell
python scripts/xdr_fleet_simulation_validate.py
```

**Output:**
```
  Validating: healthy_fleet_baseline ...  PASS
  Validating: stale_agent_detection ...  PASS
  Validating: policy_drift_visibility ...  PASS
  Validating: spool_capped_agent ...  PASS
  Validating: telemetry_lag_agent ...  PASS
  Validating: tamper_advisory_only ...  PASS
  Validating: mixed_degraded_fleet ...  PASS
  Validating: safety_invariants ...  PASS
Report written: reports/xdr_fleet_simulation_validation.json

Fleet simulation validation: 8/8 passed
```

Pass criterion: **8/8 passed**.

To run a single scenario:
```powershell
python scripts/xdr_fleet_simulation_validate.py --scenario stale_agent_detection
```

To write the report to a custom path:
```powershell
python scripts/xdr_fleet_simulation_validate.py --output reports/fleet/custom-run.json
```

---

## Running the PHP Feature Tests

```powershell
php artisan test --filter="EndpointFleetSimulationTest"
```

Pass criterion: **12 tests, 116 assertions, 0 failures**.

---

## The 7 Simulation Scenarios

### 1. `healthy_fleet_baseline`

**What it tests:** An agent with a recent heartbeat, correct config hash, and empty spool produces zero indicators and no spool warnings.

**Fixture:** Queue depth 5, dropped 0, heartbeat 30 s ago, config hash matches.

**Pass condition:** No indicators raised. `spool_capped=False`. `dropped_events=0`.

---

### 2. `stale_agent_detection`

**What it tests:** An agent that has not sent a heartbeat for 2 hours (far past the 10× interval threshold of 600 s) raises a `heartbeat_gap` advisory.

**Fixture:** `last_heartbeat_timestamp` = 7200 s ago, `heartbeat_interval_seconds` = 60.

**Pass condition:** Exactly one `heartbeat_gap` indicator with `advisory=True`, `autonomous_action=False`.

---

### 3. `policy_drift_visibility`

**What it tests:** An agent reporting a config hash that differs from the stored fleet policy config hash raises a `config_mismatch` advisory. An agent reporting the correct hash raises no indicator.

**Fixture:** `original_cfg` hashed and stored as `last_config_hash`. Agent reports `tampered_cfg` with an extra key.

**Pass condition:** `config_mismatch` indicator raised for drifted config. No indicator raised for unchanged config.

---

### 4. `spool_capped_agent`

**What it tests:** The spool cap constant is exactly 10 MiB. Spool at cap (>= 10 MiB) sets `spool_capped=True`. Spool below cap sets `spool_capped=False`.

**Fixture:** Three states — at cap (10 MiB), below cap (10 MiB − 1 byte), above cap (10 MiB + 1 KiB).

**Pass condition:** `STREAM_SPOOL_MAX_BYTES == 10485760`. `spool_capped` follows the boundary correctly.

---

### 5. `telemetry_lag_agent`

**What it tests:** `get_spool_stats()` correctly propagates `queued_events`, `dropped_events`, and `retry_count` from `StreamingState` and `QualityMetrics`.

**Fixture:** `queue_depth=1500`, `dropped_count=42`, `retry_count=3`.

**Pass condition:** Stats dict contains exactly those values.

---

### 6. `tamper_advisory_only`

**What it tests:** All three indicator types (`heartbeat_gap`, `config_mismatch`, `disabled_collector`) are raised simultaneously when all three conditions hold, and every indicator carries `advisory=True` and `autonomous_action=False`.

**Fixture:** Stale heartbeat + drifted config hash + `processes` collector disabled.

**Pass condition:** ≥ 3 indicators, all advisory, all three types present.

---

### 7. `mixed_degraded_fleet`

**What it tests:** A realistic fleet mix (healthy, stale, drifted, spool-capped) is exercised in one run. Healthy agent produces no indicators. Each degraded agent produces the expected advisory indicator(s). All indicators remain advisory.

**Fixture:** Four synthetic agents with distinct failure states.

**Pass condition:** Correct indicators per agent. No autonomous action anywhere.

---

### `safety_invariants` (cross-cutting)

**What it tests:** No forbidden autonomous enforcement APIs (`isolateHost`, `quarantineHost`, `executeShell`, `killProcess`, `autoRemediate`, `blockNetwork`, `enforcePolicy`) exist as module attributes or function definitions in `agent.py`.

**Pass condition:** Zero forbidden names found.

---

## Advisory Posture Reminder

All simulation outputs are **advisory-only**. The validator will fail if any indicator is missing `advisory=True` or has `autonomous_action=False` unset. This enforces the platform invariant:

> The endpoint fleet layer provides **visibility** into degraded states. It does **not** take corrective action automatically. Remediation is always analyst-driven and approval-governed.

See also: `ENDPOINT_FLEET_FAILURE_MATRIX.md` for the full failure-mode reference.
