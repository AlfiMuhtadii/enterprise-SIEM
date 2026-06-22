# XDR Service Contracts and Replayable Event Sourcing

This layer formalizes event contracts before the alert and incident flow is migrated further away from monolithic Laravel/Python processing.

## Versioned Event Envelope

All operational XDR topics use `schema_version = 1` and the same envelope:

```json
{
  "event_id": "evt-...",
  "event_type": "alert.created",
  "schema_version": 1,
  "occurred_at": "2026-05-13T10:00:00Z",
  "trace_id": "trace-...",
  "source_service": "alert-writer-service",
  "payload": {},
  "metadata": {}
}
```

Contract files are stored in `docs/contracts/events`.

## Topic Contracts

| Topic | Event Type | Producer | Consumer |
| --- | --- | --- | --- |
| `xdr.alerts` | `xdr.alert.raised` | correlation worker | alert writer |
| `alerts.created` | `alert.created` | alert writer | incident builder |
| `incidents.updated` | `incident.updated` | incident builder / control plane | SOC dashboard / AI |
| `ai.analysis.requests` | `ai.analysis.requested` | SOC control plane | AI/RAG service |
| `ai.analysis.results` | `ai.analysis.resulted` | AI/RAG service | SOC control plane |
| `ai.analysis.completed` | `ai.analysis.completed` | SOC control plane / AI workflow | event store |

## Replayable Operational Events

The table `xdr_operational_events` stores replayable state-change events:

| Event | Purpose |
| --- | --- |
| `alert.created` | Rebuild alert-created stream and debug alert writer output |
| `incident.updated` | Rebuild incident state and incident timelines |
| `ai.analysis.completed` | Audit and replay AI analysis completion metadata |

The event store supports:

- replay after service failure
- debug of service-to-service contracts
- audit of operational state changes
- incident state reconstruction

## Compatibility

The alert writer and incident builder still accept legacy unwrapped payloads during migration. New service output is emitted with the versioned envelope.

## Validation

Run syntax and contract smoke validation:

```powershell
python scripts\xdr_contract_validate.py
```

Run database migration:

```powershell
php artisan migrate
```

Check stored operational events:

```powershell
php artisan tinker
DB::table('xdr_operational_events')->orderByDesc('occurred_at')->limit(10)->get();
```

## Event-Driven Target Flow

```text
xdr.alerts
  -> alert-writer-service
  -> alerts.created
  -> incident-builder-service
  -> incidents.updated
```

The source of truth for SOC workflow remains PostgreSQL, while `xdr_operational_events` stores the replayable event history needed to rebuild and audit state transitions.
