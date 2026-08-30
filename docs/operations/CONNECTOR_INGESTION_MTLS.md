# Connector Ingestion mTLS

The five Go log connectors support independent TLS modes for their internal
HTTP surfaces:

| Variable | Default | Scope |
|---|---|---|
| `XDR_INTERNAL_MTLS_ENABLED` | `false` | Connector health and metrics server |
| `XDR_INTERNAL_MTLS_CLIENT_ENABLED` | server flag value | Connector client used for `XDR_INGEST_URL` |

The inheritance default preserves the original single-flag behavior. Set the
client flag explicitly when ingestion TLS and metrics TLS have different
rollout schedules.

## Ingestion-only configuration

```env
XDR_INGEST_URL=https://ingestion-gateway:8091/v1/ingest
XDR_INTERNAL_MTLS_ENABLED=false
XDR_INTERNAL_MTLS_CLIENT_ENABLED=true
XDR_INTERNAL_MTLS_CA=/etc/xdr/internal-mtls/ca.crt
XDR_INTERNAL_MTLS_CLIENT_CERT=/etc/xdr/internal-mtls/client.crt
XDR_INTERNAL_MTLS_CLIENT_KEY=/etc/xdr/internal-mtls/client.key
```

Mount the certificate directory read-only and grant the connector process read
access to the private-key group. Missing or invalid CA, certificate, or key
files cause startup to fail. The TLS client verifies the gateway hostname; do
not use an IP address unless that IP is present in the server certificate SAN.

Do not enable the client flag or change `XDR_INGEST_URL` to HTTPS until the
ingestion gateway TLS listener is enabled in the same coordinated deployment.
The gateway does not expose simultaneous plaintext and TLS listeners on port
8091.

The O365 connector keeps this private-CA transport isolated from its Microsoft
OAuth and Management Activity API client. Internal client certificates are
never attached to third-party requests.
