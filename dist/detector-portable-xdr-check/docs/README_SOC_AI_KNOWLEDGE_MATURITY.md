# SOC AI Assistance, Knowledge Base, and Detection Maturity

This phase adds analyst assistance and long-term detection-quality visibility to the endpoint-aware SOC platform.

## AI-Assisted Analyst Layer

Open an incident detail page and use the `AI-Assisted Analyst` panel.

Supported suggestion types:

- incident summary
- evidence explanation
- alert context explanation
- recommended investigation steps
- recommended response actions
- playbook suggestions
- executive narrative
- analyst assistance

The default provider is `local-heuristic`, configured by:

```env
SOC_AI_PROVIDER=local
SOC_AI_ENABLED=true
```

The provider is intentionally deterministic and does not call external APIs. AI suggestion history, input context, output, analyst acceptance/rejection, and AI-generated notes are stored in the database.

## Knowledge Base

Open `SOC Dashboard -> Knowledge base`.

Supported entry types:

- rule documentation
- IOC notes
- investigation templates
- incident lessons learned
- analyst notes
- response procedures
- MITRE reference notes

Entries support tags, markdown content, search/filter, related incident links, related rule links, and related IOC links.

## Detection Maturity Hardening

Run:

```powershell
php artisan soc:detection-maturity
```

The monitor tracks:

- alert volume anomalies
- false-positive trend changes
- benchmark degradation
- replay instability signals from quality history

Warnings are stored in `detection_quality_warnings` and surfaced on the SOC dashboard.

Laravel scheduler runs the monitor hourly:

```powershell
php artisan schedule:run
```

## SOC AI Visibility

The SOC dashboard now shows:

- AI-generated suggestions in the last 24 hours
- accepted/rejected AI suggestions
- knowledge base usage
- open detection quality warnings
- recent AI suggestions
- quality degradation warnings
