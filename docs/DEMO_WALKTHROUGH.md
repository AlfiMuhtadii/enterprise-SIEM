# Demo Walkthrough

**Estimated time:** 20–30 minutes for a complete walkthrough  
**Audience:** Technical evaluators, thesis committee, portfolio reviewers

---

## Before You Start

Ensure the platform is running:

```powershell
.\bootstrap-dev.ps1
php artisan serve
```

All URLs assume: http://localhost:8000

---

## Walkthrough A — Attack Chain Detection (10 minutes)

### A1. Start the Phishing Attack Chain

1. Navigate to **Demo Platform → Scenario Launcher**  
   URL: http://localhost:8000/demo-platform/scenarios
2. Review the `phishing_attack_chain` scenario description
3. Note: `is_lab_safe=true`, `is_destructive=false`, `has_real_malware=false`

**Checkpoint:** Scenario state is `completed` after launch

### A2. View the Attack Timeline

1. Navigate to **Demo Platform → Attack Timeline**  
   URL: http://localhost:8000/demo-platform/timeline
2. Observe multi-stage attack progression: initial access → credential theft → lateral movement → data staging
3. Note ATT&CK tactic/technique mappings on each stage

**Checkpoint:** Timeline shows ≥3 attack stages with MITRE ATT&CK annotations

### A3. Investigate via Threat Hunting

1. Navigate to **Threat Hunts**  
   URL: http://localhost:8000/threat-hunts
2. Create a new hunt on domain: `processes`
3. Filter: `is_suspicious = true`
4. Review advisory-only results

**Checkpoint:** Hunt results appear with `advisory_only=true`; no destructive output

### A4. Cross-Domain Correlation

1. Navigate to **Incidents** — observe auto-created incident from phishing chain
2. Click the incident → view investigation timeline → observe cross-domain evidence links

**Checkpoint:** Incident shows evidence from both identity events and endpoint behavioral findings

---

## Walkthrough B — Detection Quality & Governance (8 minutes)

### B1. Detection Scorecard

1. Navigate to **XDR Maturity → Detection Scorecard**  
   URL: http://localhost:8000/xdr-maturity/detection
2. Observe precision/recall metrics per rule category
3. Review ATT&CK coverage percentage
4. Note `is_advisory=true` on all scorecard entries

**Key explanation:** "We score detection quality using deterministic metrics — precision, recall, false-positive prevalence. All scoring is advisory-only and replay-safe."

### B2. XDR Maturity Tier

1. Navigate to **XDR Maturity Dashboard**  
   URL: http://localhost:8000/xdr-maturity
2. Observe overall maturity tier (target: "Managed" = 4th of 5 tiers)
3. Review component scores: detection, telemetry, investigation, response, resilience, compliance, operational

**Key explanation:** "The platform is self-assessed at the 'Managed' tier — we have defined, measured, and governance-controlled processes across all major XDR domains."

### B3. Governance Gates

1. Navigate to **XDR Certification**  
   URL: http://localhost:8000/xdr-certification
2. Review acceptance gates — note gates that are PASS vs advisory
3. Navigate to **Release Governance**  
   URL: http://localhost:8000/release-governance
4. Review the RC manifest — note `self_approve_blocked=true`, `soak_validated=true`

**Checkpoint:** All critical gates show PASS; rollback_ready=true visible

---

## Walkthrough C — SOC Workflow (5 minutes)

### C1. Alert Triage

1. Navigate to **Security Alerts**  
   URL: http://localhost:8000/security/alerts
2. Review active alerts from identity/cloud/SaaS correlation engine
3. Note alert type, severity, actor key, evidence links

### C2. SOAR Orchestration

1. Navigate to **SOAR**  
   URL: http://localhost:8000/soar
2. Review available playbooks — observe `simulation_required=true`, `dual_approval_required=true`
3. Review an execution plan — note blast_radius_score and rollback_ready

**Key explanation:** "All SOAR actions are simulation-first. No execution happens without two distinct analyst approvals and a blast-radius assessment."

### C3. Escalation & Handoff

1. Navigate to **SOC Collaboration**  
   URL: http://localhost:8000/soc/collaboration
2. Review shift handoff records — observe analyst-to-analyst handoff with context preservation
3. Review SLA tracking — observe breach detection

---

## Walkthrough D — Architecture Transparency (5 minutes)

### D1. Architecture Explorer

1. Navigate to **Demo Platform → Architecture Explorer**  
   URL: http://localhost:8000/demo-platform/architecture
2. Review active vs shadow scope visualization
3. Note: identity/cloud/SaaS = active; endpoint/DNS/proxy = shadow

### D2. Capability Matrix

1. Navigate to **Demo Platform → Capability Matrix**  
   URL: http://localhost:8000/demo-platform/capabilities
2. Review honest capability tiers: `implemented`, `advisory_only`, `shadow`, `not_implemented`
3. Point to the "not_implemented" rows — explain why (academic scope, safety posture)

**Key explanation:** "We deliberately show what's NOT implemented. Kernel EDR, live containment, and autonomous remediation are intentionally absent — this is an academic platform with a clear safety posture."

### D3. Replay Pipeline Explorer

1. Navigate to **Demo Platform → Replay Pipeline Explorer**  
   URL: http://localhost:8000/demo-platform/replay
2. Observe deterministic scenario runs: `is_deterministic=true`, `is_lab_safe=true`, `has_autonomous_remediation=false`

---

## Key Talking Points for Each Walkthrough

| Topic | Talking Point |
|---|---|
| Replay safety | "Every event uses ON CONFLICT DO NOTHING — the entire platform can be replayed from scratch and produce identical results." |
| Advisory posture | "No rule can be promoted to active without a domain-specific 6h soak. The ACTIVE_ALLOWLIST is intentionally empty for shadow domains." |
| Rollback | "XDR_CORRELATION_FALLBACK_TO_LEGACY=true at all times. Circuit breaker activates after 3 consecutive failures." |
| Bounded governance | "Every simulation, replay, and chaos test has explicit MAX_* constants. No unbounded operations." |
| Append-only audit | "100+ tables are designated append-only. No DELETE or UPDATE on audit records. Evidence is permanent." |
| SOAR safety | "Only recommend_* action types are in ALLOWED_TYPES. No execute_* commands exist in the codebase." |
