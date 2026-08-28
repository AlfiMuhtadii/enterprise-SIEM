# Native Kafka TLS

The three Go pipeline services support broker-authenticated TLS when native
Kafka transport is enabled:

- `ingestion-gateway`
- `normalizer-worker`
- `correlation-worker`

Plaintext remains the default. TLS is enabled only when both transport and TLS
are explicitly selected:

```env
XDR_KAFKA_TRANSPORT=native
XDR_REDPANDA_KAFKA_BROKERS=redpanda:9093
XDR_REDPANDA_KAFKA_TLS_ENABLED=true
XDR_REDPANDA_KAFKA_CA_CERT=/etc/xdr/internal-certs/ca.crt
```

`XDR_REDPANDA_KAFKA_CA_CERT` falls back to `XDR_REDPANDA_CA_CERT`. The broker
hostname is verified against its certificate; do not use an IP address unless
the certificate contains that IP as a subject alternative name.

For mutual TLS, configure both client files. Supplying only one is a startup
error:

```env
XDR_REDPANDA_KAFKA_CLIENT_CERT=/etc/xdr/internal-certs/client.crt
XDR_REDPANDA_KAFKA_CLIENT_KEY=/etc/xdr/internal-certs/client.key
```

The client fails closed for a missing/invalid CA, an incomplete client
certificate pair, an untrusted broker, or a broker hostname mismatch. It never
sets `InsecureSkipVerify`. `XDR_REDPANDA_KAFKA_TLS_ENABLED=false` ignores TLS
paths and preserves the existing plaintext connection behavior.

Native consumers use a separate group ID by default (`<configured-group>-native`)
because Pandaproxy and native Kafka members cannot safely share one consumer
group. Override the derived IDs only for a controlled cutover:

```env
XDR_NORMALIZER_NATIVE_GROUP=normalizer-worker-v1-native
XDR_CORRELATION_NATIVE_GROUP=correlation-worker-v1-native
```

The Compose topology keeps plaintext `9092` for compatibility and adds TLS on
`9093`. Host-side validation uses `127.0.0.1:19093`; the certificate must
contain that IP SAN. Generate the local certificate set before starting the
broker:

```powershell
python scripts/xdr_generate_internal_mtls_certs.py --generate
docker compose up -d redpanda
```

Run a real produce/consume round trip through the host TLS listener:

```powershell
docker exec detector-redpanda rpk topic create xdr.kafka.tls.validation --brokers redpanda:9092
go -C tools/shared-go/kafkanative run ./cmd/live-validate --ca ../../../storage/certs/internal-mtls/ca.crt --output ../../../reports/xdr_kafka_tls_validation.json
docker exec detector-redpanda rpk topic delete xdr.kafka.tls.validation --brokers redpanda:9092
```

The Compose TLS listener performs server authentication and encryption but
does not require a client certificate. Production brokers may set
`require_client_auth: true`; the three clients already support presenting the
optional certificate pair above. The shared-source drift gate keeps all three
service copies identical.
