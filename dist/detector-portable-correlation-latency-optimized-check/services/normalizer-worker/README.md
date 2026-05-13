# XDR Normalizer Worker

Initial Go worker for telemetry normalization.

Responsibilities:
- Normalize source telemetry into the shared XDR schema.
- Validate required fields.
- Forward normalized events to `telemetry.normalized`.
- Send malformed events to `telemetry.normalized.dlq`.
- Expose `/health` and `/metrics`.

Run a file replay:

```powershell
cd services\normalizer-worker
go run . -file ..\..\storage\logs\xdr_realistic_large.jsonl
```
