# Platform Exports

This directory contains exported reports, validation summaries, and governance artifacts from the XDR platform.

## Report Types

| Export Type | Format | Description |
|---|---|---|
| `capability_matrix` | JSON, Markdown | Full platform capability matrix with tier ratings |
| `architecture_summary` | JSON, Markdown | Architecture overview with service boundaries |
| `detection_coverage` | JSON, Markdown | Detection rule coverage by ATT&CK tactic |
| `validation_summary` | JSON, Markdown | All validator results and pass criteria |
| `thesis_readiness` | JSON, Markdown | Thesis defense readiness summary |
| `portfolio_summary` | JSON, Markdown | Portfolio showcase summary |
| `governance_summary` | JSON, Markdown | Governance subsystem summary |

## Generating Exports

### Via Platform UI

1. Navigate to: http://localhost:8000/demo-platform/showcase
2. Exports are generated via `DemoPlatformPackagingService::exportPlatformShowcase()`
3. All exports: deterministic (same content → same hash), advisory-only, not fabricated

### Via Artisan (if available)

```bash
php artisan demo:export --type=capability_matrix --format=json
php artisan demo:export --type=validation_summary --format=markdown
```

### Report Storage

Reports are tracked in `platform_showcase_exports` table (append-only). Export hash ensures determinism — the same export type/format produces the same hash for the same platform state.

## Validation Reports

Validation script reports are stored in `reports/`:

```
reports/
├── xdr_contract_validation.json
├── xdr_rule_registry_validation.json
├── resilience/
│   ├── resilience-validation-report.json
│   └── fault-injection-report.json
└── xdr_correlation_soak_6h.json
```

Generate all reports:

```powershell
python scripts/xdr_contract_validate.py --output reports/xdr_contract_validation.json
python scripts/xdr_rule_registry_validate.py
python scripts/xdr_resilience_validate.py --output reports/resilience/resilience-validation-report.json
python scripts/xdr_fault_injection.py --output reports/resilience/fault-injection-report.json
```
