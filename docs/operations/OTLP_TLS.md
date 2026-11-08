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

This phase secures the client side only. The current local Tempo profile still
listens on plaintext OTLP. Production must place Tempo behind a TLS-enabled
collector or reverse proxy before setting the HTTPS endpoint; server-side TLS
termination is tracked as the next phase of `ENT-SEC-NO-TLS-INTERNAL`.
