# Strangler Migration Architecture for XDR

The platform is moving from monolithic Laravel/Python processing toward specialized services without replacing the SOC control plane.

## Current Control Plane

Laravel remains the authoritative system for:
- Authentication and RBAC.
- SOC dashboard.
- Incidents and workflow state.
- Reports and audit trail.
- Operational health summaries.

## Extracted Services

```text
Telemetry Sources
  -> Go Ingestion Gateway
  -> Redpanda telemetry.raw
  -> Go Normalizer Worker
  -> Redpanda telemetry.normalized
  -> XDR Correlation Worker
  -> xdr.alerts / incidents.updated
  -> Laravel SOC Control Plane

Laravel SOC Control Plane
  -> ai.analysis.requests
  -> FastAPI AI/RAG Service
  -> ai.analysis.results
  -> Laravel SOC Control Plane
```

## Service Boundaries

| Service | Runtime | Responsibility |
| --- | --- | --- |
| ingestion-gateway | Go | Signed ingestion, rate limiting, raw topic publish |
| telemetry-normalizer | Go | Schema normalization, malformed event DLQ, normalized topic publish |
| xdr-correlation | Go | Identity/cloud/SaaS correlation in shadow/staged mode, XDR alert generation |
| alert-writer | FastAPI | Idempotent alert persistence and `alerts.created` publication |
| incident-builder | FastAPI | Incident grouping, evidence timeline, and `incidents.updated` publication |
| ai-rag | FastAPI | Defensive AI analysis, retrieval, embeddings |
| soc-control-plane | Laravel | RBAC, incidents, workflow, dashboards, reports |

## Migration Rule

Each extracted service must expose:
- `/health`
- `/metrics`
- deterministic retry or DLQ behavior
- clear input/output topics
- dashboard-visible operational status

## Validation

Use `scripts/xdr_operational_validate.py` for realistic 50k+ event replay validation before moving another workload out of Laravel/Python.
