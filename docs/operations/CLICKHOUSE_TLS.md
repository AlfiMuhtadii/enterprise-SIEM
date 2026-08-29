# ClickHouse TLS

ClickHouse HTTPS is optional in the local profile and mandatory in the production overlay. When enabled, the server removes plaintext HTTP port 8123 and listens on HTTPS port 8443.

## Generate Certificates

The shared internal certificate must include the `clickhouse` DNS SAN:

```powershell
python scripts/xdr_generate_internal_mtls_certs.py --generate
```

Private keys remain under `storage/certs/internal-mtls/` and must not be committed.

## Local Opt-In

Set these values in `.env` before recreating ClickHouse and any containerized clients:

```env
CLICKHOUSE_TLS_ENABLED=true
CLICKHOUSE_TLS_REQUIRED=false
XDR_CLICKHOUSE_HTTP_URL=https://clickhouse:8443
XDR_CLICKHOUSE_VERIFY_TLS=true
XDR_CLICKHOUSE_CA_CERT=/etc/xdr/clickhouse-certs/ca.crt
```

Host-run Python commands instead use `https://127.0.0.1:18443` and `storage/certs/internal-mtls/ca.crt`.

```powershell
docker compose up -d --force-recreate clickhouse-tls-init clickhouse
python scripts/xdr_clickhouse_tls_compose_validate.py
```

## Production

`docker-compose.prod.yml` sets both `CLICKHOUSE_TLS_ENABLED=true` and `CLICKHOUSE_TLS_REQUIRED=true`. Startup fails if TLS is disabled or `server.crt`, `server.key`, or `ca.crt` is missing. Application, queue, scheduler, and telemetry worker containers use `https://clickhouse:8443` with CA verification enabled.

Render the exact production topology before deployment:

```powershell
docker compose --env-file .env.production.example -f docker-compose.yml -f docker-compose.prod.yml config --quiet
python scripts/xdr_clickhouse_tls_compose_validate.py --output reports/clickhouse_tls_compose_validation.json
```

Do not set `XDR_CLICKHOUSE_VERIFY_TLS=false` in production. Certificate rotation requires regenerating the internal certificate set and recreating `clickhouse-tls-init`, ClickHouse, and its clients so the server and CA mounts change together.
