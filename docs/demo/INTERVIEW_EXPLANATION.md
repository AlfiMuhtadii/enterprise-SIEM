# Interview Explanation

## Short Explanation

This is a SOC-oriented detection and investigation platform. It ingests web and endpoint telemetry, normalizes events, runs correlation and rule-based detection, deduplicates alerts, creates incidents, supports analyst workflow, enriches evidence with threat intelligence, and provides AI-assisted investigation with guardrails.

## Architecture Answer

The architecture has five main layers:

- Telemetry layer: Laravel security logs and lightweight endpoint agent events.
- Detection layer: rules, correlation, deduplication, MITRE mapping, and alert generation.
- Incident layer: incident model, timeline, notes, status, SLA, and analyst assignment.
- Intelligence layer: IOC enrichment, knowledge base, RAG, and AI analyst support.
- Operations layer: dashboard, exports, notifications, audit trail, response workflow, and enterprise readiness checks.

## Detection Answer

The system detects attacks by combining explicit signals and correlated behavior. A single event can produce a low-confidence signal, while multiple related events across time, entity, or MITRE technique increase confidence. This makes the platform more useful than a single hardcoded detector because it can reason across alert chains, endpoint activity, IOC matches, and incident context.

## Response Safety Answer

Response actions are approval-gated. Safe agent commands such as `collect-now` or `flush-local-queue` can be queued after approval. High-impact containment actions such as host isolation, IOC blocking, or quarantine mode are simulation-only in this version. They create audit records and expected-effect reports without changing real infrastructure.

## AI Answer

AI is used as an assistant, not as an autonomous decision maker. It summarizes incidents, explains evidence, suggests investigation steps, and uses knowledge-base retrieval for citations. Guardrails restrict it to defensive security analysis, and analysts can accept or reject suggestions.

## Enterprise Readiness Answer

The project includes environment validation, modeled soak testing, retention/cost reporting, production deployment notes, backup/restore documentation, and HA planning. These features make it easier to move from prototype behavior toward operational deployment.

## Tradeoffs

- The current containment layer is intentionally simulated for safety.
- External threat intelligence integrations are provider-abstracted, so real API keys can be added later.
- Soak testing is safe and modeled by default; real high-volume replay should be executed in staging.
- The endpoint agent is lightweight and focused on telemetry visibility, not full EDR replacement.

## Strong Closing

The important part of this system is not only that it detects attacks, but that it supports the SOC lifecycle: telemetry, detection, triage, investigation, enrichment, response approval, audit, reporting, and operational validation.
