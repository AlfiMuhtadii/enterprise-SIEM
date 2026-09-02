# MinIO Cold-Tier TLS

The optional `data-tiering` profile remains plaintext-compatible for local
development. The production Compose overlay enables CA-verified HTTPS without
publishing the MinIO API or console to the host.

Generate the internal certificates before production startup:

```powershell
python scripts/xdr_generate_internal_mtls_certs.py --generate
docker compose --env-file .env.production.example -f docker-compose.yml -f docker-compose.prod.yml --profile data-tiering --profile app up -d minio app queue scheduler
```

`internal-mtls-certs-init` creates MinIO's required `public.crt` and
`private.key` aliases in the named certificate volume. MinIO starts with
`--certs-dir /etc/xdr/internal-mtls/minio`; its healthcheck verifies the
`localhost` SAN against the mounted CA using the image's bundled `mc` client
(the pinned image does not contain `curl`). Laravel's S3 client uses
`AWS_ENDPOINT=https://minio:9000` and `AWS_CA_BUNDLE` for hostname and chain
verification. Do not set the CA bundle to `false` or disable verification.

The S3 access key and secret remain application credentials and must be
provisioned separately. This phase encrypts the transport; it does not replace
bucket policy, lifecycle, backup, or restore validation.

Validate the rendered topology:

```powershell
python scripts/xdr_minio_tls_compose_validate.py --output reports/xdr_minio_tls_validation.json
```
