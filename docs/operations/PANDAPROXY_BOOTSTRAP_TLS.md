# Pandaproxy Topic Bootstrap TLS

The topic bootstrap tool keeps its local plaintext default for the base Compose
stack. For a TLS-enabled Pandaproxy listener, provide the private CA explicitly:

```powershell
python scripts/xdr_topic_bootstrap.py `
  --pandaproxy https://redpanda:8083 `
  --tls-ca storage/certs/internal-mtls/ca.crt
```

`--tls-ca` uses Python's default hostname and certificate-chain verification.
It is rejected with an `http://` URL and there is no insecure-skip option.
Failure to load the CA exits with code 2 before any network or topic-creation
operation.

This TLS context is scoped to the Pandaproxy `GET /topics` request. It contains
no client certificate or private key and is not propagated to Docker or `rpk`.
The subsequent topic creation command still runs inside the Redpanda container:

```text
docker compose exec -T redpanda rpk topic create ...
```

For host-side access, use a URL whose hostname is present in the server
certificate. The repository-generated certificate includes `redpanda` and
`localhost`; do not replace either with an unrelated IP address unless that IP
is present in the certificate SAN.

Local compatibility remains:

```powershell
python scripts/xdr_topic_bootstrap.py --pandaproxy http://127.0.0.1:8082
```
