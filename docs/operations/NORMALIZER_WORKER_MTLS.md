# Normalizer Worker Mutual TLS

Production Compose requires mutual TLS for every `normalizer-worker` HTTP endpoint. Local Compose remains plaintext-compatible by default.

Generate the shared private CA and leaf certificates:

```powershell
python scripts/xdr_generate_internal_mtls_certs.py --generate
```

`internal-mtls-certs-init` copies the five required files into a named volume. Server and client keys are root-owned, group `44444`, mode `0640`; normalizer, ingestion, and Laravel clients receive that supplemental group and mount the volume read-only.

The ingestion gateway deliberately separates its mTLS modes. `XDR_INTERNAL_MTLS_CLIENT_ENABLED=true` protects the normalizer metrics poll, while `XDR_INTERNAL_MTLS_ENABLED=false` keeps the externally consumed ingestion API unchanged. The legacy global flag still enables both modes when no client override is supplied.

Validate the resolved local and production topology:

```powershell
python scripts/xdr_normalizer_mtls_compose_validate.py
```

Production removes normalizer port 8092 from the host and routes Laravel health, DLQ replay, resilience, and ingestion backpressure calls through the internal HTTPS service name. Ingestion port 8091 remains published because endpoint telemetry transport is a separate cutover.

`normalizer_metrics_poll_successes` on the ingestion metrics endpoint increments only after a valid normalizer metrics document is decoded. Use it to distinguish an idle queue from a failed TLS poll.
