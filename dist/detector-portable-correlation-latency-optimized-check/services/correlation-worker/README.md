# XDR Correlation Worker

Initial Go shadow correlation worker.

Mode:
- Shadow only.
- Does not write production `security_alerts`.
- Does not create incidents as source of truth.
- Used to compare alert count, type match, evidence match, latency, throughput, and duplicate rate against the existing Python/Laravel correlation path.

Run:

```powershell
cd services\correlation-worker
go run . -addr :8093
```
