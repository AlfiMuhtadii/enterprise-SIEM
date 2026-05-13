# Enterprise Readiness, Containment Simulation, and HA Planning

This layer adds operational readiness checks and safe containment drills without changing real endpoint, firewall, DNS, or EDR state.

## Containment Simulation

Supported approval-gated simulation actions:

- `isolate-host`: models network containment for an endpoint.
- `block-ioc`: models adding an IP/domain/hash IOC to blocklists.
- `policy-quarantine`: models switching an endpoint to a quarantine collection policy.

Flow:

1. Analyst creates a response recommendation from `/soc/agents`.
2. The recommendation stays in `pending_approval`.
3. Analyst approves or rejects the response.
4. Approval creates a row in `containment_simulations`.
5. The action is audited in `security_audit_trails`.

Safety boundary:

- No network rules are applied.
- No DNS/firewall/proxy/EDR integration is changed.
- No endpoint command is queued for containment simulation.
- The result records the expected effect and can be used for tabletop exercises.

## Multi-Environment Validation

Run environment validation:

```bash
php artisan soc:env-validate local
php artisan soc:env-validate staging
php artisan soc:env-validate production
```

The command stores results in `enterprise_validation_runs` and checks:

- application key
- database reachability
- core SOC tables
- storage writability
- queue configuration
- production-safe defaults

Production warnings include:

- `APP_DEBUG=true`
- `QUEUE_CONNECTION=sync`
- insecure session cookie configuration

## Larger-Scale Soak Test

Run a safe modeled soak test:

```bash
php artisan soc:soak-test --events=100000 --environment=staging
```

The command stores throughput and lag estimates in `enterprise_validation_runs`.

Use this before a real high-volume replay to estimate:

- event volume
- batch count
- processing duration
- expected throughput
- queue lag risk

## Retention and Cost Report

Run:

```bash
php artisan soc:retention-cost --days=30 --environment=production
```

The command stores reports in `retention_cost_reports` and estimates:

- current logical storage
- monthly storage growth
- low/high monthly storage cost
- tables that may need archiving
- hot/archive retention recommendations

## HA Planning

Recommended production topology:

- Laravel app: at least 2 stateless replicas behind a load balancer.
- Queue workers: separate worker pool with autoscaling based on queue lag.
- Scheduler: single active scheduler instance with lock support.
- Database: managed PostgreSQL/MySQL with automated backup and PITR.
- Stream layer: Redpanda/Kafka with replication factor >= 3 for production.
- Cache/session: Redis with persistence or managed HA Redis.
- Object storage: archive reports, forensic bundles, and backups outside the app container.
- Observability: scrape health endpoints and queue/worker metrics.

Minimum RPO/RTO target:

- RPO: 15 minutes for incident and alert database.
- RTO: 1 hour for dashboard and workflow recovery.
- Backup verification: daily metadata check and weekly restore test.

## Operational Commands

```bash
php artisan soc:env-validate production
php artisan soc:soak-test --events=100000 --environment=staging
php artisan soc:retention-cost --days=90 --environment=production
```
