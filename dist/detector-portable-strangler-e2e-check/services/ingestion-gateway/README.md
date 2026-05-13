# XDR Ingestion Gateway

Initial Go strangler service for raw telemetry ingestion.

Responsibilities:
- Validate HMAC signatures.
- Rate-limit ingestion.
- Accept JSON object or JSON array telemetry batches.
- Publish accepted records to Redpanda/Kafka topic `telemetry.raw`.
- Expose `/health` and `/metrics`.

Run:

```powershell
cd services\ingestion-gateway
go run . -addr :8091
```
