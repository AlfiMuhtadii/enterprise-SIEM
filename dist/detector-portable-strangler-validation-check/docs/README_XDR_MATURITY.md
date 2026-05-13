# XDR Operational Maturity Layer

This layer matures the functioning distributed XDR-like prototype without adding offensive C2, stealth, or persistence research.

## High-Volume Streaming Maturity

```bash
php artisan xdr:stream-metrics
php artisan xdr:stream-maturity --consumers=4 --partitions=8 --replay-events=50000
```

Tracks:

- worker partitioning
- parallel consumers
- replay pressure
- backpressure
- DLQ count
- retry count
- rebalance count
- partition lag
- stream saturation

## Large-Scale Telemetry Realism

```bash
python scripts/xdr_generate_large_dataset.py --normal=50000 --malicious=2500 --output storage/logs/xdr_large_mixed.jsonl
php artisan xdr:large-scale-validate --normal=50000 --malicious=2500 --duration-minutes=60 --noise=0.35
```

Generates:

- FP/FN per telemetry domain
- p50/p95/p99 latency metrics
- throughput saturation metrics
- correlation degradation warnings

## Detection Engineering Maturity

```bash
php artisan xdr:rule-maturity --environment=staging
php artisan xdr:rule-maturity --environment=production
```

Supports:

- Sigma-like rule packs
- staging vs production rules
- rule confidence tuning
- dependency graph metadata
- drift analysis
- regression history
- detection quality score

Rule pack:

```text
storage/app/xdr_rule_packs/identity_cloud_sigma_like.json
```

## Identity-Centric XDR

```bash
php artisan xdr:identity-risk --minutes=1440
```

Tracks:

- risky identity scoring
- session anomaly detection
- MFA anomaly correlation
- privileged identity monitoring
- repeated cross-service login anomaly
- identity risk timeline

## Multi-Stage Attack Reconstruction

```bash
php artisan xdr:attack-reconstruct --minutes=1440
```

Builds:

- attack graph
- chain confidence score
- campaign grouping
- cross-domain timeline
- linked evidence expansion
- attack flow visualization metadata

Supported domains:

- email
- identity
- endpoint
- DNS
- cloud
- SaaS
- proxy/firewall

## Storage Scaling Maturity

```bash
php artisan xdr:storage-maturity
```

Tracks:

- ClickHouse partition optimization guidance
- OpenSearch rollover policy metadata
- hot/warm/cold retention
- archive pipeline readiness
- compression/storage cost metrics

## Operational Hardening

```bash
php artisan xdr:recovery-validate --scenario=degraded-storage
```

Tracks:

- long-running soak readiness
- crash recovery behavior
- node restart recovery
- degraded-mode handling
- service dependency failure handling
- distributed recovery reporting

## Dashboard Visibility

The SOC dashboard shows:

- stream maturity
- rule lifecycle score
- identity risk trend
- attack reconstruction count/confidence
- storage cost/tiering
- recovery status

The operational metrics API also exposes this data under:

```text
/soc/api/metrics -> xdr_distributed.maturity
```
