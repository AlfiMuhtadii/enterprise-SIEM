# OTLP Client TLS Verification

All first-party OTLP/HTTP exporters use the same optional private CA setting:

```env
XDR_OTEL_EXPORTER_ENDPOINT=https://otel-collector:4318/v1/traces
XDR_OTEL_CA_CERT=/etc/xdr/internal-mtls/ca.crt
```

The setting applies to the Laravel control plane, the alert-writer and
incident-builder Python services, and the ingestion, normalization, and
correlation Go services. The clients retain normal platform/system trust when
the CA setting is empty. A configured CA is accepted only for an HTTPS
endpoint, must name a readable certificate file, and preserves hostname
verification. Invalid trust configuration fails before network activity.

OTLP export remains best-effort: an export error is swallowed or logged and
never fails the telemetry or analyst request that produced the span. Local
plaintext endpoints continue to work only when `XDR_OTEL_CA_CERT` is empty.

The base Compose profile remains plaintext and opt-in for local development.
The production overlay starts Tempo without host-published ports and replaces
its receiver configuration with `infra/analytics/tempo/tempo-tls.yaml`. That
receiver accepts OTLP/HTTP only over TLS 1.2 or newer. Tempo's query/readiness
API remains HTTP on the internal network for Grafana and its health check.

Render the production topology before deployment:

```powershell
docker compose --env-file .env.production.example `
  -f docker-compose.yml -f docker-compose.prod.yml config --quiet
python scripts/xdr_otlp_tls_compose_validate.py
```

The shared certificate must be regenerated after upgrades so its SAN set
contains `tempo`. Production clients use `https://tempo:4318/v1/traces` and the
read-only CA at `/etc/xdr/internal-mtls/ca.crt`. No client certificate is sent:
the OTLP receiver uses server-authenticated TLS, not mutual TLS.
