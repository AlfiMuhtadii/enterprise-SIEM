# SOC Detection Tuning, Threat Intelligence, Playbooks, and Reporting

This phase adds operational detection engineering workflows on top of the existing endpoint-aware SOC platform.

## Detection Tuning

Open `SOC Dashboard -> Detection tuning`.

Analysts can:

- mark alerts as `true_positive`, `false_positive`, `benign`, or `needs_review`
- create suppression rules by alert type, actor, IP, or rule ID
- set suppression expiration
- apply active suppressions to matching alerts
- add per-rule tuning notes
- review rule effectiveness and tuning suggestions

All feedback, suppression, and notes are attributed to the logged-in analyst and written to the audit trail.

## Threat Intelligence

Open `SOC Dashboard -> Threat intel`.

Supported IOC workflows:

- manual IOC entry
- JSONL feed import
- CSV feed import
- local watchlist/blocklist storage
- IP/domain/hash/url matching against recent alerts
- IOC hit tracking
- alert evidence enrichment with IOC matches
- IOC expiration

CLI import is also available:

```powershell
php artisan soc:ioc-import path\to\feed.jsonl --format=jsonl --source=internal-feed
```

## Playbook Automation

Open an incident detail page and create a playbook.

Supported templates:

- `generic`
- `web_attack`
- `endpoint_compromise`

Playbooks include investigation tasks, approval tasks, response tasks, closure validation, assignment, progress tracking, workflow history, and audit logs.

## Executive Reporting

Open `SOC Dashboard -> Reports`.

Reports include:

- incident statistics
- MTTA / MTTR
- top threats
- rule performance
- false-positive trends
- analyst activity
- severity distribution

Reports can be generated as HTML or JSON. Scheduled generation is registered in Laravel scheduler:

```powershell
php artisan schedule:run
```

Manual CLI generation:

```powershell
php artisan soc:generate-report weekly
php artisan soc:generate-report monthly
```
