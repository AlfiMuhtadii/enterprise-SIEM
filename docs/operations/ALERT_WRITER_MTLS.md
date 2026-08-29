# Alert Writer Mutual TLS

Production Compose requires mutual TLS for the `alert-writer-service` HTTP listener. Its Redpanda, PostgreSQL, and OpenSearch transports retain their separately configured TLS controls.

Generate the shared private CA and leaf certificates:

```powershell
python scripts/xdr_generate_internal_mtls_certs.py --generate
```

`internal-mtls-certs-init` copies the five required files into a named volume. Server and client keys are root-owned, group `44444`, mode `0640`; alert-writer and Laravel clients receive that supplemental group and mount the volume read-only. Production startup fails when certificate material is incomplete.

Validate the resolved local and production topology:

```powershell
python scripts/xdr_alert_writer_mtls_compose_validate.py
```

Production removes host port 8095 and routes Laravel service health and resilience checks through `https://alert-writer-service:8095`. The image healthcheck verifies the private CA and presents its own client certificate. Local Compose remains plaintext-compatible unless `XDR_ALERT_WRITER_MTLS_ENABLED=true`.
