# Phase 1: SIEM-lite Persistence (Postgres)

This phase persists security telemetry from `storage/logs/security.jsonl` into Postgres table `security_events`.
Primary path uses a Python ingester (no full Laravel boot required).

## 1) Migrate

```bash
php artisan migrate
```

## 2) Ingest Events (Python, preferred)

Install dependencies:

```bash
pip install -r scripts/requirements-ingest.txt
```

Run incremental ingest:

```bash
python scripts/ingest_security_events.py
```

Run from beginning (safe dedup by deterministic `event_id`):

```bash
python scripts/ingest_security_events.py --from-start
```

Optional DSN override:

```bash
python scripts/ingest_security_events.py --dsn "host=127.0.0.1 port=5432 dbname=detector user=postgres password=postgres"
```

Notes:

- Offset checkpoint: `storage/app/security_ingest_py.offset`
- Dedup key: `event_id = HMAC_SHA256(ts|request_id|event_type|path|ip)`
- Insert strategy: `ON CONFLICT (event_id) DO NOTHING`

## 3) Ingest Events (Artisan fallback)

```bash
php artisan security:ingest
```

## 4) Run Built-in Report

```bash
php artisan security:report --minutes=15
```

Outputs:

- Top IP in last 15 minutes
- Failed logins per IP in last 15 minutes
- 404 spikes grouped by minute

## 5) Direct SQL Queries

Top IP (15 minutes):

```sql
select ip, count(*) as total
from security_events
where ts >= now() - interval '15 minutes'
  and ip is not null
group by ip
order by total desc
limit 10;
```

Failed logins per IP:

```sql
select ip, count(*) as failed_logins
from security_events
where ts >= now() - interval '15 minutes'
  and event_type = 'auth_login_failed'
  and ip is not null
group by ip
order by failed_logins desc
limit 10;
```

404 spike:

```sql
select date_trunc('minute', ts) as minute, count(*) as count_404
from security_events
where ts >= now() - interval '15 minutes'
  and status = 404
group by date_trunc('minute', ts)
order by minute desc;
```

File version of these queries:

- `scripts/sql/security_queries.sql`

## 6) Retention (TTL-style)

Manual retention job:

```bash
php artisan security:retention --events-days=30 --alerts-days=90
```

Also scheduled daily in `app/Console/Kernel.php`.
