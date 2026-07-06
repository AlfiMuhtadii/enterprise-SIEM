# Limitations and Future Work

## Scope Boundaries (Intentional)

The following capabilities are explicitly outside the current scope of this platform:

| Boundary | Reason |
|---|---|
| Kernel-level EDR telemetry | Requires kernel driver; out of current scope |
| Live host containment/isolation | Destructive automation; safety boundary |
| Real malware execution | Lab safety; not required for detection validation |
| Commercial hyperscale SLA | Enterprise hardening (HA, tenant isolation, TLS) is tracked separately and in progress — see the enterprise-blocker backlog |
| Long-running production soak beyond identity/cloud/SaaS | Other domains pending domain-specific 6h soak PASS |

---

## Technical Limitations

### Detection Scope
- **Active correlation** is limited to identity/cloud/SaaS domain. Endpoint, DNS/proxy/firewall, and threat-intel rules remain shadow-only, pending domain-specific 6-hour soak validation.
- **False positive rates** in shadow domains are not yet characterized under real production telemetry volumes.

### Scalability
- The current deployment is a single-node development configuration. HA governance, multi-tenant isolation, and cluster topology are implemented at the advisory/simulation layer, not tested under real distributed load.
- Telemetry ingestion capacity is validated at 77,000 eps under test conditions. Real-world burst characteristics may differ.

### AI/ML Component
- The multiclass logistic regression baseline is trained on synthetic/labeled telemetry. Drift under real adversarial noise has not been characterized.
- AI-RAG analyst assist uses Qdrant vector store with heuristic fallback; accuracy on novel attack descriptions is not formally benchmarked.

### Endpoint Agent
- The endpoint agent collects user-space telemetry only. Rootkit-level evasion, kernel-injected code, and hypervisor-level attacks are outside the visibility scope.
- Windows agent support is partial; primary development target is Linux.

---

## Future Work

### Short-Term (Next Phase)
1. **Domain-specific 6h soak validation** for endpoint behavioral domain → enable staged promotion of endpoint shadow rules
2. **Real telemetry integration** with a controlled lab environment to characterize FP/FN rates under realistic noise
3. **Logistic regression model improvement** — evaluate ensemble approaches and drift detection

### Medium-Term
4. **Multi-node deployment validation** — test HA governance under actual distributed failure scenarios
5. **Windows endpoint agent parity** — sysmon-based collection on Windows to match Linux /proc telemetry depth
6. **Automated detection rule promotion pipeline** — governance-gated CI/CD integration for rule lifecycle

### Long-Term
7. **Federated threat intelligence** — structured IOC sharing across tenant boundaries under privacy-preserving constraints
8. **Graph neural network correlation** — replace rule-based cross-domain correlation with trained GNN for attack chain inference
9. **Formal verification of replay safety** — use TLA+ or similar to verify idempotency and determinism properties of the event pipeline

---

## Known Constraints

- `ACTIVE_ALLOWLIST` in `xdr_rule_registry_validate.py` remains intentionally empty; no rule can be promoted without domain-specific 6h soak
- `XDR_CORRELATION_FALLBACK_TO_LEGACY=true` must be preserved; removing rollback capability is a forbidden change
- All SOAR response actions are `recommend_*` only; `execute_*` action types are not implemented by design
