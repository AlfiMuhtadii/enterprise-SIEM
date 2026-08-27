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
XDR_REDPANDA_KAFKA_BROKERS=redpanda-tls.example.internal:9093
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

The repository's default Redpanda listener remains plaintext for local
compatibility. Point the variables above at an independently configured TLS
listener until the compose listener phase is completed. The in-process Kafka
tests exercise real TLS producer and consumer handshakes with a generated CA;
the shared-source drift gate keeps all three service copies identical.
