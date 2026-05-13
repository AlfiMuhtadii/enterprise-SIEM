# Architecture Summary

## High-Level Platform

```mermaid
flowchart LR
    A[Laravel Web App] --> B[Security Event Logger]
    C[Endpoint Agent] --> D[Secure Agent API]
    B --> E[Telemetry Store]
    D --> E
    E --> F[Correlation and Rule Engine]
    F --> G[Alerts]
    G --> H[Incidents]
    H --> I[SOC Dashboard]
    H --> J[AI Analyst and RAG]
    H --> K[Response Workflow]
    K --> L[Forensic Collection]
    K --> M[Containment Simulation]
    G --> N[Threat Intel Enrichment]
```

## Telemetry Ingestion Flow

```mermaid
sequenceDiagram
    participant Agent
    participant API as Secure Agent API
    participant Store as telemetry_events
    participant Corr as Correlation Engine
    participant Alert as security_alerts
    Agent->>API: Signed telemetry batch
    API->>Store: Normalize and insert events
    Store->>Corr: Replay or stream processing
    Corr->>Alert: Create deduplicated alert
```

## Incident Workflow

```mermaid
stateDiagram-v2
    [*] --> open
    open --> triaged
    triaged --> investigating
    investigating --> resolved
    investigating --> false_positive
    open --> escalated
    escalated --> investigating
```

## Response and Containment Simulation

```mermaid
flowchart TD
    A[Alert or Incident] --> B[Recommended Response]
    B --> C{Analyst Approval}
    C -->|Reject| D[Rejected + Audit]
    C -->|Approve safe command| E[Queue Agent Command]
    C -->|Approve containment| F[Create Containment Simulation]
    F --> G[Audit Simulation Result]
    E --> H[Track Command Status]
```

## AI and Knowledge Retrieval

```mermaid
flowchart LR
    A[Incident Evidence] --> B[AI Guardrails]
    C[Knowledge Base] --> D[Semantic Retrieval]
    E[Rules and IOC Notes] --> D
    D --> F[Cited Context]
    B --> G[LLM Provider Abstraction]
    F --> G
    G --> H[AI Suggestion]
    H --> I[Analyst Review]
```

## Deployment Topology

```mermaid
flowchart TB
    LB[Load Balancer] --> APP1[Laravel App 1]
    LB --> APP2[Laravel App 2]
    APP1 --> DB[(Database)]
    APP2 --> DB
    APP1 --> Q[Queue or Stream Layer]
    APP2 --> Q
    Q --> W1[Worker Pool]
    Q --> W2[Worker Pool]
    S[Scheduler] --> DB
    W1 --> DB
    W2 --> DB
    APP1 --> OBS[Health and Metrics]
    APP2 --> OBS
```

## Developer Notes

- Keep detection logic declarative where possible.
- Keep containment actions simulation-only unless production integrations are explicitly designed.
- Use audit logging for analyst actions, AI output review, workflow changes, and response approvals.
- Use enterprise validation commands before deployment and after configuration changes.
