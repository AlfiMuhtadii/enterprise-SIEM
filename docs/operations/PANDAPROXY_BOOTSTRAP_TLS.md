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

## Demo Alert Publisher

The deterministic demo-alert publisher uses the same scoped, CA-verifying
Pandaproxy transport:

```powershell
python scripts/xdr_send_demo_alert.py `
  --rest-url https://localhost:8083 `
  --tls-ca storage/certs/internal-mtls/ca.crt
```

Its local default remains `http://127.0.0.1:8082`. Supplying `--tls-ca` with
that plaintext URL exits 2 before timestamp generation, payload construction,
or network activity. The CA context is applied only to the alert publish
request and never includes a client certificate or private key.

## Stream-Bus Redpanda Backend

The stream-bus utility supports the same verified transport for finite JSONL
publishes:

```powershell
python scripts/xdr_stream_bus.py produce `
  --backend redpanda `
  --topic telemetry.raw `
  --file path/to/events.jsonl `
  --redpanda-rest https://localhost:8083 `
  --redpanda-tls-ca storage/certs/internal-mtls/ca.crt
```

`--redpanda-tls-ca` is rejected unless the action is `produce`, the backend is
`redpanda`, and the URL is HTTPS. Failed records are still written to the
existing `storage/streams/<topic>.dlq.jsonl` safety path with `dlq=true` in the
JSONL envelope, but the command now reports `failed=<count>` and exits 1 when
any publish failed. Exit 0 therefore means every input record was accepted by
Pandaproxy; it no longer means only that the loop completed.

## JSONL Tail Producer

The long-running JSONL producer can verify the same private CA and endpoint
hostname while tailing a local event file:

```powershell
python scripts/stream_producer_jsonl.py `
  --file storage/logs/security.jsonl `
  --rest-url https://localhost:8083 `
  --tls-ca storage/certs/internal-mtls/ca.crt `
  --topic security_events
```

`KAFKA_REST_TLS_CA` is the environment equivalent of `--tls-ca`. A configured
CA requires an HTTPS URL; invalid CA paths and plaintext URL combinations exit
2 before the producer creates or truncates its offset state file. Omitting the
CA preserves the existing local plaintext default. The TLS context uses
Python's default certificate and hostname verification and is scoped only to
the Pandaproxy request.
