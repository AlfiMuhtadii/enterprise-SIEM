# Demo Video Script

## 1. Opening

Explain the problem:

> Modern web and endpoint attacks produce scattered telemetry. This platform centralizes telemetry, detects suspicious activity, creates incidents, supports analyst workflow, enriches evidence, and records safe response simulations.

Show:

- `/soc` dashboard
- incident count
- recent alerts
- MITRE overview

## 2. Detection Flow

Show a recent alert and explain:

- source telemetry
- detector/rule name
- severity
- affected entity
- evidence chain

Say:

> The system supports rule-based and correlation-based detection, then deduplicates alerts and links them into incidents.

## 3. Incident Investigation

Open an incident page and show:

- incident summary
- related alerts
- affected entities
- MITRE mapping
- investigation timeline
- analyst notes

Say:

> This is the SOC case view. The analyst can track status, severity, notes, timeline, and workflow history.

## 4. Threat Hunting

Open `/soc/hunts` and filter by:

- host
- user
- process
- IP/domain
- time range

Say:

> Threat hunting allows analysts to search raw and correlated telemetry without waiting for an alert.

## 5. Threat Intelligence

Open `/soc/threat-intel` and show:

- IOC list
- import options
- enrichment action
- IOC hit tracking

Say:

> IOC enrichment adds context from local and external-style threat intelligence sources.

## 6. AI Analyst Assistance

Generate or show AI assistance:

- incident summary
- evidence explanation
- recommended investigation steps
- citations from SOC knowledge base
- analyst accept/reject workflow

Say:

> AI output is defensive, evidence-bound, and reviewable. It is stored with audit history and analyst feedback.

## 7. Response and Containment Simulation

Open `/soc/agents`.

Create recommendation:

- source type: incident
- source id: existing incident id
- action: `isolate-host`
- reason: `contain suspected endpoint`

Approve the workflow.

Show:

- response status `approved_simulated`
- audit trail
- containment simulation result

Say:

> The containment workflow is approval-gated and safe. In this build it simulates isolate, block IOC, and quarantine decisions without changing real infrastructure.

## 8. Enterprise Readiness

Run:

```bash
php artisan soc:env-validate local
php artisan soc:soak-test --events=10000 --environment=local
php artisan soc:retention-cost --days=30 --environment=local
```

Show stored reports or command output.

Say:

> These commands help validate deployment readiness, estimate high-volume behavior, and plan retention cost.

## 9. Closing

Close with:

> The result is a defensive SOC-oriented detection platform: telemetry collection, correlation, incident workflow, analyst investigation, AI assistance, threat intelligence, and safe response simulation.
