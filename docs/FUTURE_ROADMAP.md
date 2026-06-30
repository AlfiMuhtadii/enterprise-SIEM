# Future Roadmap

**Platform:** Hybrid Near Real-Time Web Attack Detection Platform  
**Current state:** RC1, feature-complete academic platform  
**Date:** 2026-05-24

This roadmap describes improvements that are out of scope for the current academic platform but represent the natural evolution toward a production-grade system.

---

## Priority 1 — Kernel Telemetry (eBPF)

**Description:** Replace or augment the Python user-space agent with an eBPF-based kernel telemetry collector.

**Value:** Captures system calls, raw network events, kernel module loads, and memory operations that are invisible to user-space agents. Closes the gap against advanced evasion techniques.

**Requirements:**
- Linux kernel 5.4+ with BTF support
- Go-based BPF loader (libbpf-go or Cilium ebpf)
- New event schemas: `syscall_events`, `memory_events`, `network_socket_events`
- New Redpanda topic: `telemetry.kernel`
- 6h soak for kernel domain before promotion to active

**Academic reference:** eBPF-based XDR is used by Falco, Tracee, Tetragon — this would bring the platform to parity with production EDR architectures.

---

## Priority 2 — Live Host Containment (Multi-Analyst Approved)

**Description:** Implement live host containment as a formal response action with:
- Three-analyst approval (elevated from dual-approval)
- Automated rollback after configurable timeout
- Network isolation only (not process kill)
- Full audit trail with analyst identity and timestamp

**Constraints:**
- Must remain bounded: containment timeout MAX=3600s
- Must require explicit rollback confirmation
- Must never be triggered autonomously

**Blocked by:** Production Kubernetes deployment + real network control plane

---

## Priority 3 — Automated ML Retraining Pipeline

**Description:** Build a data labeling and retraining pipeline:
- Analyst verdict capture (true positive / false positive / benign)
- Label propagation to training corpus
- Shadow model deployment (new model runs shadow, old model stays active)
- Champion/challenger validation before promotion
- Automated precision/recall regression tests on each retrain

**Technology:** Python MLflow or similar, with model registry integration

**Academic value:** Demonstrates continuous improvement loop in adversarial detection — key gap in current academic posture.

---

## Priority 4 — Kubernetes Production Deployment

**Description:** Deploy the platform on a Kubernetes cluster:
- Go services as Deployments with HPA
- Redpanda as StatefulSet (3-node cluster)
- PostgreSQL as StatefulSet with streaming replication
- Grafana/OpenSearch/Qdrant in appropriate configurations
- Helm charts for reproducible deployment

**The HA governance subsystem is already implemented** — it models cluster topology, failover coordination, and HA validation. Kubernetes deployment would make this governance actionable rather than simulated.

---

## Priority 5 — Explainable AI (XAI) for Detection

**Description:** Augment logistic regression outputs with SHAP explanations per alert:
- Feature importance per classification decision
- Counterfactual explanations ("this alert would not have fired if X had been different")
- Analyst-readable explanation summaries
- Explanation stored in alert record for audit trail

**Technology:** SHAP library, Python FastAPI extension to alert-writer-service

---

## Priority 6 — Real Threat Intelligence Feed

**Description:** Connect to a real threat intelligence feed (MISP, OpenCTI, or commercial TI):
- IOC ingestion via normalized `threat_intel` events
- IOC correlation in Go correlation-worker (currently shadow-only)
- IOC reputation scoring per alert
- Automated IOC expiry governance

**Blocked by:** Threat-intel domain requires 6h soak before promotion to active.

---

## Priority 7 — Multi-Tenant SaaS Deployment

**Description:** Productize the platform for multi-tenant SaaS delivery:
- Tenant provisioning automation
- Per-tenant detection rule customization
- Per-tenant data isolation at Redpanda partition level
- Billing and usage governance
- Commercial deployment packages

**The multi-tenant isolation governance is already implemented** — it validates cross-tenant boundaries, replay isolation, and evidence integrity per tenant. This roadmap item would make it commercially deployable.

---

## Priority 8 — Mobile / Browser Threat Telemetry
 
 **Description:** Extend the endpoint agent to mobile platforms (iOS/Android) and browser extensions:
 - Mobile telemetry: app install events, permission changes, network activity
 - Browser telemetry: URL visits, credential form usage, extension activity
 - New normalized event schemas
 - New detection rules for mobile/browser attack vectors
 
 ---

## Priority 9 — High-Performance Ingest & Storage Architecture
 
 **Description:** Refactor the hot-ingestion data path and database architecture to eliminate HTTP REST intermediate overhead, transactional write-locks, and connection pooling leaks:
 - **Native Kafka TCP Ingestion**: Transition Go ingestion gateway and Go workers from Pandaproxy HTTP REST API to native binary Kafka TCP protocol (port 9092) with zstd/snappy compression.
 - **ClickHouse Storage Split**: Route raw and normalized telemetry directly to a ClickHouse OLAP cluster in bulk batches, reserving PostgreSQL OLTP database solely for relational incident management and RBAC.
 - **Mutual TLS (mTLS) Security**: Secure all container-to-container REST/gRPC communications using cryptographically signed client certs (mTLS) instead of shared static tokens.
 - **Thread-Safe In-Memory Cache for IOCs**: Replace synchronous HTTP threat intelligence lookup calls in hot matching loops with a fast, thread-safe in-memory cache.
 
 ---
 
 ## Academic Extensions
 
 | Extension | Description |
 |---|---|
 | Graph neural network for lateral movement | Replace rule-based lateral movement with GNN on the entity graph |
 | Federated learning for multi-tenant detection | Share model improvements across tenants without sharing raw telemetry |
 | Natural language investigation queries | LLM-powered natural language interface over the 177-domain hunt engine |
 | Automated attack scenario generation | Use LLMs to generate novel attack scenarios from ATT&CK matrix entries |
 

