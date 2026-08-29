# AI-RAG Mutual TLS

Production Compose requires mutual TLS between Laravel and `ai-rag-service`. Local Compose remains plaintext-compatible unless `XDR_INTERNAL_MTLS_ENABLED=true`.

Generate the shared private CA and leaf certificates:

```powershell
python scripts/xdr_generate_internal_mtls_certs.py --generate
```

`internal-mtls-certs-init` copies the five required files into a named volume. Server and client keys are root-owned, group `44444`, mode `0640`; Laravel and AI-RAG receive that supplemental group and mount the volume read-only. Production startup fails when TLS is enabled without complete certificate material.

Validate the resolved local and production topology:

```powershell
python scripts/xdr_ai_rag_mtls_compose_validate.py
```

The Laravel client verifies the `ai-rag-service` SAN against the mounted CA and presents `client.crt`/`client.key`. Do not reuse this phase's production enablement claim for the other internal microservices; their Compose cutovers remain separate.
