# Demo Scenario Index

**Platform:** Hybrid Near Real-Time Web Attack Detection Platform  
**Date:** 2026-05-24  
**All scenarios:** Synthetic, replay-safe, lab-safe, advisory-only, bounded

---

## Scenario Overview

| Scenario | ATT&CK Tactics | Complexity | Duration |
|---|---|---|---|
| `phishing_attack_chain` | Initial Access, Credential Access, Lateral Movement, Collection | High | ~3 min walkthrough |
| `lolbin_execution_chain` | Execution, Defense Evasion, Discovery | Medium | ~2 min walkthrough |
| `noisy_enterprise_simulation` | Multiple (synthetic noise) | High (volume) | ~2 min walkthrough |
| `identity_compromise` | Initial Access, Privilege Escalation, Persistence | Medium | ~2 min walkthrough |
| `lateral_movement` | Lateral Movement, Execution | Medium | ~2 min walkthrough |
| `persistence_install` | Persistence | Low | ~1 min walkthrough |
| `data_exfiltration` | Collection, Exfiltration, Command & Control | High | ~3 min walkthrough |
| `ransomware_precursor` | Discovery, Collection, Impact (precursors only) | Medium | ~2 min walkthrough |
| `lolbin_network_pivot` | Execution, Defense Evasion, Lateral Movement | Medium | ~2 min walkthrough |
| `multi_stage_cloud_attack` | Initial Access, Privilege Escalation, Collection | High | ~3 min walkthrough |

---

## Scenario Details

### phishing_attack_chain

**Description:** A credential phishing email leads to account compromise. The attacker uses stolen credentials to authenticate from an unusual geolocation, escalates privileges via a SaaS admin panel, and stages data for exfiltration.

**Detection domains:** `identity_provider_events`, `saas_audit_events`, `cross_domain_correlations`, `attack_chain_timelines`

**ATT&CK techniques:**
- T1566.002 — Spearphishing Link
- T1078 — Valid Accounts
- T1098 — Account Manipulation
- T1048 — Exfiltration Over Alternative Protocol

**Fixture file:** `fixtures/demo/scenarios/phishing_attack_chain.json`

**Key indicators to show:**
- Geo anomaly in identity provider event
- Unusual admin action in SaaS audit
- Cross-domain correlation linking identity + SaaS events
- Attack stage timeline showing 4-stage progression

---

### lolbin_execution_chain

**Description:** An attacker uses legitimate Windows binaries (LOLBins) to execute a payload without introducing new executables. The chain uses `wscript.exe → powershell.exe → certutil.exe` to download and execute a remote payload.

**Detection domains:** `execution_chains`, `processes`, `endpoint_anti_evasion_indicators`

**ATT&CK techniques:**
- T1059.001 — PowerShell
- T1105 — Ingress Tool Transfer
- T1218 — Signed Binary Proxy Execution

**Fixture file:** `fixtures/demo/scenarios/lolbin_execution_chain.json`

**Key indicators to show:**
- LOLBin execution chain in behavioral analytics
- Parent-child process ancestry anomaly
- Anti-evasion indicator (unsigned child of signed parent)
- Shadow rule detection (no active alert since endpoint domain is shadow)

---

### noisy_enterprise_simulation

**Description:** Simulates a high-volume enterprise environment with a mix of legitimate and suspicious telemetry. Used to demonstrate noise filtering, FP/FN analysis, and analyst workload management.

**Detection domains:** `noisy_enterprise_simulations`, `analyst_workload_snapshots`, `false_positive_tuning_reports`

**Fixture file:** `fixtures/demo/scenarios/noisy_enterprise_simulation.json`

**Key indicators to show:**
- Alert volume vs actionable alert ratio
- FP prevalence rate per rule category
- Analyst fatigue indicator when alert volume > threshold
- Suppression governance (advisory recommendations only)

---

### identity_compromise

**Description:** A cloud identity (Okta/Azure AD) is compromised via password spray. The attacker enumerates admin accounts, bypasses MFA, and creates a persistent backdoor account.

**Detection domains:** `identity_provider_events`, `baseline_anomaly_scores`, `attack_chain_timelines`

**ATT&CK techniques:**
- T1110.003 — Password Spraying
- T1556 — Modify Authentication Process
- T1136 — Create Account

---

### lateral_movement

**Description:** An attacker with foothold on one host uses process ancestry chains to move laterally. `wmic.exe` and remote service creation are used.

**Detection domains:** `processes`, `execution_chains`, `cross_host_correlation_runs`

**ATT&CK techniques:**
- T1021 — Remote Services
- T1047 — Windows Management Instrumentation
- T1543 — Create or Modify System Process

---

### persistence_install

**Description:** A threat actor installs persistence via Windows Registry Run keys and a scheduled task. Both mechanisms are detected via the endpoint persistence inventory.

**Detection domains:** `persistence_items`, `endpoint_registry_timelines`, `endpoint_anti_evasion_indicators`

**ATT&CK techniques:**
- T1547.001 — Registry Run Keys
- T1053.005 — Scheduled Task

---

### data_exfiltration

**Description:** After establishing a foothold, an attacker stages data and exfiltrates via DNS tunneling. Beaconing is detected via the beacon pattern detector.

**Detection domains:** `beacon_patterns`, `dns_events`, `network_behavioral_findings`

**ATT&CK techniques:**
- T1020 — Automated Exfiltration
- T1048.003 — Exfiltration Over Unencrypted Non-C2 Protocol
- T1071.004 — DNS

---

### ransomware_precursor

**Description:** Pre-ransomware behavioral indicators: file enumeration, shadow copy deletion precursors, privilege escalation, and lateral movement. The scenario stops before encryption — it demonstrates the detection of precursor behaviors.

**Detection domains:** `processes`, `endpoint_privilege_escalations`, `behavioral_findings`

**ATT&CK techniques:**
- T1078 — Valid Accounts
- T1082 — System Information Discovery
- T1486 (precursor indicators only) — Data Encrypted for Impact

**Note:** This scenario demonstrates precursor detection only. No encryption or destructive payload is simulated.

---

## Fixture File Format

All scenario fixtures follow this structure:

```json
{
  "scenario_name": "phishing_attack_chain",
  "metadata": {
    "is_lab_safe": true,
    "is_destructive": false,
    "has_real_malware": false,
    "has_autonomous_remediation": false,
    "advisory_only": true,
    "replay_safe": true,
    "max_events": 200
  },
  "stages": [...],
  "events": [...]
}
```

---

## Seeding Demo Scenarios

```powershell
# Seed all demo fixtures
php artisan db:seed --class=DemoScenarioSeeder

# Or via bootstrap (includes migration + seed)
.\bootstrap-dev.ps1
```
