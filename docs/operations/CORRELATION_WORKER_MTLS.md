# Correlation Worker Mutual TLS

Production Compose requires mutual TLS between Laravel and `correlation-worker`. Local Compose remains plaintext-compatible unless `XDR_INTERNAL_MTLS_ENABLED=true`.

Generate the shared private CA and leaf certificates:

```powershell
python scripts/xdr_generate_internal_mtls_certs.py --generate
```

`internal-mtls-certs-init` copies the five required files into a named volume. Server and client keys are root-owned, group `44444`, mode `0640`; Laravel and correlation-worker receive that supplemental group and mount the volume read-only. Production startup fails when TLS is enabled without complete certificate material.

Validate the resolved local and production topology:

```powershell
python scripts/xdr_correlation_mtls_compose_validate.py
```

The Laravel cutover and strangler-status clients verify the `correlation-worker` SAN against the mounted CA and present `client.crt`/`client.key`. The production override does not publish port 8093 to the host. Remaining internal HTTP services must be cut over and validated separately.
