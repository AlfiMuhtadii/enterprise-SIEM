# Phase 2: Automatic Dataset Labeling

Ground truth is derived from `attack_runs` windows created automatically by `sim:*` commands.

## 1) Migrate

```bash
php artisan migrate
```

Creates `attack_runs` with fields:

- `id`
- `attack_type`
- `started_at`
- `ended_at`
- `metadata`

## 2) Generate Labeled Windows

Run simulations. Each command writes one `attack_runs` record:

```bash
php artisan sim:bruteforce
php artisan sim:scan
php artisan sim:injection
```

Attack labels produced:

- `bruteforce`
- `scan`
- `injection`

Events outside any run window are labeled `normal`.

## 3) Ingest Events

```bash
python scripts/ingest_security_events.py
```

## 4) Export Labeled Training Dataset

### CSV via Artisan

```bash
php artisan security:export-dataset --output=storage/app/security_dataset.csv
```

### CSV via Python

```bash
python scripts/export_labeled_dataset.py --format=csv --output=storage/app/security_dataset.csv
```

### Parquet via Python

```bash
python scripts/export_labeled_dataset.py --format=parquet --output=storage/app/security_dataset.parquet
```

## 5) Validate Label Coverage

Count by label:

```sql
select
  coalesce(ar.attack_type, 'normal') as label,
  count(*) as total
from security_events se
left join lateral (
  select attack_type
  from attack_runs
  where se.ts >= started_at
    and se.ts <= coalesce(ended_at, now())
  order by started_at desc
  limit 1
) ar on true
group by coalesce(ar.attack_type, 'normal')
order by total desc;
```
