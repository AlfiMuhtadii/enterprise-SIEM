# Qdrant TLS

Qdrant REST (`6333`) and gRPC (`6334`) use plaintext in the default local profile. Production Compose enables TLS on both protocols and fails before Qdrant starts if the internal certificate bundle is missing.

## Generate Certificates

```powershell
python scripts/xdr_generate_internal_mtls_certs.py --generate
```

The server certificate includes `qdrant`, `localhost`, and `127.0.0.1` SANs. Private keys under `storage/certs/internal-mtls` are gitignored.

## Enable Locally

Set these values in `.env`:

```env
QDRANT_TLS_ENABLED=true
QDRANT_TLS_REQUIRED=true
XDR_QDRANT_URL=https://127.0.0.1:6333
SOC_QDRANT_BASE_URL=https://127.0.0.1:6333
XDR_QDRANT_VERIFY_TLS=true
XDR_QDRANT_CA_CERT=storage/certs/internal-mtls/ca.crt
```

Then recreate Qdrant and its one-shot initializer:

```powershell
docker compose up -d --force-recreate qdrant-tls-init qdrant
python scripts/xdr_qdrant_tls_compose_validate.py
```

Compose application containers use `/etc/xdr/qdrant-certs/ca.crt`; host-side scripts use `storage/certs/internal-mtls/ca.crt`. The validator enforces 37 server, client, mount, and healthcheck invariants. Do not set `XDR_QDRANT_VERIFY_TLS=false` outside bounded diagnostics.

## Production

`docker-compose.prod.yml` forces TLS enabled and required, removes host port publication, sets every Laravel Qdrant URL to `https://qdrant:6333`, and mounts the CA read-only. The Qdrant image does not contain `curl`; its healthcheck uses `openssl s_client` with hostname and CA verification.

Certificate rotation requires rerunning the generator, recreating `qdrant-tls-init`, and restarting Qdrant. Qdrant can reload REST certificates on its configured TTL, but gRPC certificate reload still requires restart.
