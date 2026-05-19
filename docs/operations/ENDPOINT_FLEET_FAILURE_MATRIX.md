# Endpoint Fleet Failure Mode Matrix

## Overview

This matrix maps each detectable failure mode to its detection mechanism, advisory indicator type, and operational response. **No row in this table triggers automatic enforcement.** All findings are advisory — operators review and decide.

---

## Failure Mode Matrix

| Failure Mode | Detection Layer | Advisory Indicator | Severity | Automatic Action | Operator Action |
|---|---|---|---|---|---|
| Agent silent (no heartbeat) | `check_tamper_visibility()` | `heartbeat_gap` | MEDIUM | **None** | Investigate agent health; check connectivity |
| Config hash mismatch | `check_tamper_visibility()` | `config_mismatch` | MEDIUM | **None** | Review config; re-push fleet policy if warranted |
| Required collector disabled | `check_tamper_visibility()` | `disabled_collector` | LOW | **None** | Enable collector in fleet policy |
| Spool at 10 MiB cap | `get_spool_stats()` | `spool_capped=True` in snapshot | HIGH | **None** | Investigate network connectivity; clear backlog |
| Events dropped from queue | `get_spool_stats()` | `dropped_events > 0` in snapshot | HIGH | **None** | Investigate delivery failures; replay from spool |
| Agent health degraded | `EndpointFleetService.getStaleAgents()` | Health state `stale` / `offline` | MEDIUM | **None** | Investigate agent process on host |
| Policy assignment not applied | `EndpointFleetService.checkPolicyDrift()` | Fleet dashboard policy drift list | MEDIUM | **None** | Re-assign or re-push policy |
| Agent enrollment failed | `EndpointAgentEnrollmentEvent.EVENT_FAILED` | Enrollment audit log entry | HIGH | **None** | Retry enrollment; check credentials |
| Agent tamper (process/binary) | `EndpointFleetService.detectTamperEvents()` | `tamper_event` (is_advisory=True) | HIGH | **None** | Escalate to incident; manual investigation |
| Telemetry delivery lag | Heartbeat spool snapshot lag metrics | `queue_depth` + lag summary | MEDIUM | **None** | Check ingestion gateway health |
| Disk pressure on agent host | `get_spool_stats()` | `disk_pressure=True` in snapshot | HIGH | **None** | Free disk space; increase spool path quota |

---

## Detection Thresholds

| Parameter | Value | Source |
|---|---|---|
| Spool cap | 10 MiB (`STREAM_SPOOL_MAX_BYTES`) | `agent.py` constant |
| Heartbeat stale threshold | `heartbeat_interval_seconds × 10` | `check_tamper_visibility()` |
| Policy drift grace period | 300 seconds | `EndpointFleetService::POLICY_DRIFT_MIN_AGE_SECONDS` |
| Stale agent threshold | 30 minutes without heartbeat | `EndpointFleetService.getStaleAgents()` |
| Spool warning threshold | `dropped_events >= 10` in snapshot | `EndpointFleetService.countSpoolWarnings()` |

---

## Indicator Structure

Every advisory indicator produced by `check_tamper_visibility()` carries:

```json
{
  "type": "<indicator_type>",
  "advisory": true,
  "autonomous_action": false,
  ...evidence fields...
}
```

Every tamper event stored via `EndpointFleetService.detectTamperEvents()` carries:

```
is_advisory = true (always, enforced at DB level)
autonomous_action = (not stored — advisory posture is the only posture)
```

---

## Forbidden Responses

The following responses are **forbidden** at every layer:

| Action | Layer | Status |
|---|---|---|
| `isolateHost()` | agent.py | **Not implemented** |
| `quarantineHost()` | agent.py | **Not implemented** |
| `executeShell()` | agent.py | **Not implemented** |
| `killProcess()` | agent.py | **Not implemented** |
| `autoRemediate()` | agent.py, PHP service | **Not implemented** |
| `blockNetwork()` | agent.py | **Not implemented** |
| Automatic fleet policy rollback | PHP service | **Not implemented** |
| Automatic agent revocation | PHP service | **Not implemented** |

The `safety_invariants` scenario in `xdr_fleet_simulation_validate.py` verifies these APIs are absent at every run.

---

## Operational Interpretation Guide

### `heartbeat_gap` indicator

The agent has not checked in for more than 10× its configured heartbeat interval. Common causes:
- Network connectivity loss between host and ingestion gateway
- Agent process crashed or was killed
- Host powered off or suspended
- High system load causing agent starvation

**Do not** automatically revoke the agent or assume compromise. Check host connectivity first.

### `config_mismatch` indicator

The SHA-256 hash of the current running config differs from the last known-good hash stored in the fleet policy. Common causes:
- Manual config file edits on the host
- Another process modified the config
- Config delivered by a different fleet policy assignment

**Do not** automatically re-push config. Verify the change is authorized before reassigning the policy.

### `disabled_collector` indicator

A collector that the fleet policy requires to be enabled is currently disabled in the agent config. This reduces telemetry coverage.

**Do not** automatically re-enable collectors. Verify the collector is safe to enable and not disabled for a legitimate reason (e.g., performance constraint on that host).

### `spool_capped=True`

The local spool file has reached 10 MiB. Events are being dropped. This means the agent cannot successfully deliver telemetry to the ingestion gateway and is accumulating a backlog.

**Investigate delivery path first.** Check ingestion gateway health, TLS cert validity, network routing. Do not assume host compromise.

### `dropped_events > 0`

Events were dropped from the in-memory streaming queue before they could be written to the spool or delivered. This indicates sustained delivery failure.

**Check agent logs.** High `dropped_events` combined with `spool_capped=True` means the host is running blind — no telemetry is reaching the platform.

---

## Safety Boundary Reminder

> This platform is an **advisory detection layer**. It provides visibility into what is happening across the fleet. It does **not** take action on your behalf. Every investigation, policy decision, and remediation is operator-driven and governed by the approval workflow in the SOC control plane.
>
> The endpoint agent is telemetry-only. It has no capability to kill processes, block network connections, modify firewall rules, or take any action that affects the running state of the host.

For the full safety boundary definition: `CLAUDE.md` → Non Goals section.
