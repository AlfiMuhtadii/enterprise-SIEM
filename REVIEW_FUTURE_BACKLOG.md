# Future Architectural Backlog (Resource-Heavy Staging Tasks)

This document tracks future backlog tasks that are specifically deferred because they **make the server and system heavy** (requiring high CPU/RAM, running multiple heavy container replicas, or conducting heavy machine learning retraining workloads). 

---

## Future Resource-Heavy Tasks

| Task ID | Component | Title | Resource Cost | Est. Effort |
|---|---|---|---|---|
| **HA-DRILL-01** | Infrastructure | Redpanda Multi-Node Chaos & Failover Validation | High RAM/CPU (Multi-Broker Cluster) | 3–4 Weeks |
| **ML-DRIFT-03** | AI / MLOps | In-Process ML Model Retraining and Concept Drift Pipeline | High CPU/GPU (Model Training Runs) | 5–7 Weeks |

---

## 1. Task HA-DRILL-01: Redpanda Multi-Node Chaos & Failover Validation
* **Description**: Provision a full 3-broker Redpanda HA cluster in a dedicated high-spec staging environment using `docker-compose.ha.yml`. Integrate chaos testing utilities (e.g., Chaos Mesh or custom partition injectors) to randomly kill brokers, drop network packets, or cause partition split-brains during high-rate telemetry ingestion.
* **Why it is deferred**: 
  * Running three Redpanda brokers simultaneously requires a minimum of 6GB to 8GB of RAM dedicated solely to message brokers. In development laptops or standard single-node servers, this causes immediate memory exhaustion, swapping, and system freezes.
* **Target Outcome**: 100% failover success with zero message loss and `<5s` group rebalance latency during broker restarts.

---

## 2. Task ML-DRIFT-03: In-Process ML Model Retraining & Concept Drift Pipeline
* **Description**: Re-integrate the legacy machine-learning modeling scripts (e.g., `train_ai_detector.py`, `mlops_drift_monitor.py`) as a live background pipeline. Build a streaming analytics worker that reads raw event patterns from Kafka, calculates feature vector drift, triggers auto-retraining on new datasets, and deploys model updates to the Go correlation engine via gRPC/REST without service downtime.
* **Why it is deferred**:
  * Machine learning model training (anomaly profiling and features extraction) is a highly CPU/GPU bound process. Running training epochs inside loops on the SIEM server will consume 100% CPU, starving the real-time normalizer and correlation workers of processor cycles.
* **Target Outcome**: Fully automated ML lifecycle with automated detection of concept drift and zero-downtime model promotion.
