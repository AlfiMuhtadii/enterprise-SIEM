# Review Rejected — Tasks Discarded

This file tracks all proposed tasks suggested by Gemini/Antigravity that were validated and rejected by Claude with technical reasoning.

---

## Rejected Suggestions

### Task DB-3: Seeder users locked out in strict mode
* **Category**: E. Documentation Bug / Design Posture
* **Source**: [REVIEW_ALL.md](file:///D:/project/Detector/REVIEW_ALL.md#finding-db-3-seeder-users-locked-out-in-strict-mode)
* **Rejection Reason**: The seeders (`UserSeeder.php` and `DemoSocSeeder.php`) are intended for building a local demonstration/showcase state. Strict tenancy mode (`XDR_TENANT_STRICT_MODE=true`) is disabled by default in local settings. Restricting seeder user access would disrupt local presentations and thesis defense walkthroughs.
* **Status**: **REJECTED**

### Task DB-4: Unscoped demo alerts/incidents (tenant_id = NULL) in DemoSocSeeder
* **Category**: E. Documentation Bug / Design Posture
* **Source**: [REVIEW_ALL.md](file:///D:/project/Detector/REVIEW_ALL.md#finding-db-4-unscoped-demo-alertsincidents-tenant_id--null-in-demosocseeder)
* **Rejection Reason**: Similar to DB-3, these demo alerts and incidents are seeded for generic presentation visibility. Restricting them to a single tenant breaks the pre-packaged global dashboard showcase layout.
* **Status**: **REJECTED**

### Task ENV-3: Inconsistent controller naming conventions
* **Category**: D. Code Smell / Maintainability
* **Source**: [REVIEW_ALL.md](file:///D:/project/Detector/REVIEW_ALL.md#finding-env-3-inconsistent-controller-naming-conventions)
* **Rejection Reason**: Renaming established plural and singular resource controller names (`AdvisoryFindingsController` vs `DlqController`) would require rewriting routes, models, and references, introducing regression risks for code style with zero functional or security benefits.
* **Status**: **REJECTED**

### Task INFRA-3: No memory/CPU limits on intensive containers
* **Category**: B. Conditional Risk
* **Source**: [REVIEW_ALL.md](file:///D:/project/Detector/REVIEW_ALL.md#finding-infra-3-no-memorycpu-limits-on-intensive-containers)
* **Rejection Reason**: Container CPU and memory limits are production orchestrator concerns (e.g. Kubernetes, AWS ECS). Setting hard resource limits directly in the local development `docker-compose.yml` can cause container crashes on lower-spec developer machines.
* **Status**: **REJECTED**

### Task INFRA-4: Grafana provisioning mounts not read-only
* **Category**: C. Hardening Opportunity
* **Source**: [REVIEW_ALL.md](file:///D:/project/Detector/REVIEW_ALL.md#finding-infra-4-grafana-provisioning-mounts-not-read-only)
* **Rejection Reason**: Provisioning folders should be writeable during local development to allow dashboard exports and edits directly within the containerized application. Restricting to read-only (`:ro`) is a production-only deployment concern.
* **Status**: **REJECTED**

### Task RAG-1: Empty knowledge base on fresh deployment
* **Category**: E. Documentation Bug / Design Posture
* **Source**: [REVIEW_ALL.md](file:///D:/project/Detector/REVIEW_ALL.md#finding-rag-1-empty-knowledge-base-on-fresh-deployment)
* **Rejection Reason**: Seeding RAG knowledge vectors belongs to the runtime ingest workflow rather than database migration seeders. The `AiGuardrails` component already handles empty retrieval inputs gracefully with a fallback and a visible warning on the analyst dashboard.
* **Status**: **REJECTED**
