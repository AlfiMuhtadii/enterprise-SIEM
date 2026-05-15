# Endpoint Shadow Correlation Rules

Shadow-only correlation rules for endpoint telemetry. These rules run against `telemetry.normalized` endpoint events and publish alerts to `xdr.alerts.shadow.endpoint`. Alerts do NOT enter the active incident workflow.

**Current status:** shadow-only. Endpoint domain cutover is not approved.  
**Engine path:** `correlateEndpointShadow()` in `services/correlation-worker/main.go`  
**HTTP endpoint:** `POST /v1/correlate-endpoint-shadow`  
**Output topic:** `xdr.alerts.shadow.endpoint` (not `xdr.alerts`)

---

## Rule Index

| rule_id | version | severity | confidence | ATT&CK |
|---|---|---|---|---|
| suspicious_parent_child_process | v1 | high | 0.80 | T1059, T1204 |
| powershell_encoded_command | v1 | high | 0.85 | T1059.001 |
| suspicious_temp_file_write | v1 | high | 0.78 | T1105, T1027 |
| failed_login_burst | v1 | medium | 0.72 | T1110 |
| suspicious_dns_query | v1 | medium | 0.68 | T1071.004, T1568 |
| suspicious_outbound_connection | v1 | medium | 0.65 | T1571, T1095 |

---

## Rule 1: suspicious_parent_child_process

**rule_id:** `suspicious_parent_child_process`  
**version:** v1  
**title:** Suspicious Parent-Child Process Relationship  
**severity:** high  
**confidence:** 0.80

### Description

Detects when a suspicious child process (cmd.exe, powershell.exe, wscript.exe, etc.) is spawned by an unexpected parent process associated with office or productivity applications (winword.exe, excel.exe, outlook.exe, msdt.exe, mspub.exe). This pattern is commonly observed in phishing-delivered macro execution and MSDT exploit chains.

### ATT&CK Mapping

- T1059 — Command and Scripting Interpreter
- T1059.001 — PowerShell (when child is powershell.exe)
- T1204.002 — Malicious File (when triggered via Office document)

### Event Requirements

- Event type: `process_start`
- Same event batch must contain parent process event with matching `process.pid`
- Child `process.name` in: `cmd.exe`, `powershell.exe`, `wscript.exe`, `cscript.exe`, `mshta.exe`, `regsvr32.exe`, `rundll32.exe`, `certutil.exe`
- Parent `process.name` (matched by `process.ppid` → parent `process.pid`) in: `winword.exe`, `excel.exe`, `powerpnt.exe`, `outlook.exe`, `msdt.exe`, `mspub.exe`

### Evidence Fields

| Field | Description |
|---|---|
| `parent_process` | Detected parent process name |
| `child_process` | Detected child process name |
| `ppid` | Parent process ID |

### False Positive Notes

- Legitimate macro-enabled documents used by automation workflows
- IT admin scripts invoked from Outlook rules
- Document conversion pipelines using Office COM automation

### Suppression Guidance

Suppress by host (known automation servers), by parent-child pair (vetted workflow), or by process path (signed corporate tooling with known hash).

### Replay Validation Expectations

- Input: batch containing `winword.exe` (pid=1234) followed by `cmd.exe` (ppid=1234)
- Expected: 1 alert with `rule_id = suspicious_parent_child_process`
- Benign (no match): `explorer.exe` spawning `cmd.exe` → no alert

---

## Rule 2: powershell_encoded_command

**rule_id:** `powershell_encoded_command`  
**version:** v1  
**title:** PowerShell Encoded Command Execution  
**severity:** high  
**confidence:** 0.85

### Description

Detects PowerShell launched with an encoded command argument (`-enc`, `-e`, `-EncodedCommand`). Encoding is frequently used to obfuscate malicious payloads from command-line logging and endpoint security tools.

### ATT&CK Mapping

- T1059.001 — Command and Scripting Interpreter: PowerShell
- T1027 — Obfuscated Files or Information
- T1140 — Deobfuscate/Decode Files or Information

### Event Requirements

- Event type: `process_start`
- `process.name` is `powershell.exe` or `pwsh.exe` (case-insensitive)
- `process.command_line` contains at least one of: ` -enc `, ` -e `, `-encodedcommand`, `/enc `, `/e `

### Evidence Fields

| Field | Description |
|---|---|
| `command_line` | Full command line containing the encoded argument |

### False Positive Notes

- Legitimate automation scripts using encoded commands for special character handling
- Deployment tools (SCCM, Ansible, SaltStack) that encode commands for transport
- Some remote management tools encode commands by default

### Suppression Guidance

Suppress by process path hash (known signed deployment tool), by parent process (known IT automation), or by user (service account with documented automation use).

### Replay Validation Expectations

- Input: `process_start` for `powershell.exe` with `-enc ZQBjAGgAbwAgAGgAZQBsAGwAbwA=` in command_line
- Expected: 1 alert with `rule_id = powershell_encoded_command`
- Benign: `powershell.exe -Command Get-Process` → no alert

---

## Rule 3: suspicious_temp_file_write

**rule_id:** `suspicious_temp_file_write`  
**version:** v1  
**title:** Executable Written to Temporary Directory  
**severity:** high  
**confidence:** 0.78

### Description

Detects executable or script files written to temporary directories. Threat actors frequently stage payloads in Temp, %AppData%\Local\Temp, or /tmp before execution. Writing a PE, DLL, batch, PowerShell script, or VBScript to these locations is a common indicator of payload staging.

### ATT&CK Mapping

- T1105 — Ingress Tool Transfer
- T1027 — Obfuscated Files or Information
- T1059 — Command and Scripting Interpreter (for script drops)

### Event Requirements

- Event type: `file_write`
- `file.operation` in: `create`, `modify`, `overwrite`
- `file.path` contains a temp directory pattern (case-insensitive): `\temp\`, `\tmp\`, `\appdata\local\temp\`, `/tmp/`, `/temp/`
- `file.path` ends with an executable extension: `.exe`, `.dll`, `.bat`, `.ps1`, `.vbs`, `.js`, `.hta`, `.scr`

### Evidence Fields

| Field | Description |
|---|---|
| `file_path` | Full path of the written file |
| `operation` | File operation type |

### False Positive Notes

- Software installers legitimately extract to Temp before installation
- Browser downloads to Temp before user action
- Update mechanisms staging updates in Temp

### Suppression Guidance

Suppress by process.hash (known installer), by process.name (known updater), or by file.path pattern (vendor-specific temp staging directories).

### Replay Validation Expectations

- Input: `file_write` with `file.path = C:\Users\alice\AppData\Local\Temp\update_payload.exe`, `file.operation = create`
- Expected: 1 alert with `rule_id = suspicious_temp_file_write`
- Benign: `file_write` to `C:\Users\alice\Documents\report.docx` → no alert

---

## Rule 4: failed_login_burst

**rule_id:** `failed_login_burst`  
**version:** v1  
**title:** Failed Login Burst  
**severity:** medium  
**confidence:** 0.72

### Description

Detects a burst of failed login or MFA failures for the same user on the same host within a single correlation window. Three or more failures indicate a potential brute force or credential spray attempt. Endpoint-sourced login events (from the local security log) are distinct from identity telemetry from cloud SSO providers.

### ATT&CK Mapping

- T1110 — Brute Force
- T1110.001 — Password Guessing
- T1110.003 — Password Spraying

### Event Requirements

- Event type: `login_event`
- `auth.action` is `login_failed` or `mfa_failed`
- Same `host` and `user` combination appears ≥ 3 times in the event batch

### Evidence Fields

| Field | Description |
|---|---|
| `failed_count` | Number of failed login events in the batch |
| `threshold` | Configured burst threshold (currently 3) |

### False Positive Notes

- Users mistyping passwords several times legitimately
- Locked accounts (already locked, still generating failure events)
- Automated health checks using expired credentials

### Suppression Guidance

Suppress by user (service accounts with known rotation delays), by host (authentication test systems), or below a higher threshold for low-noise environments.

### Replay Validation Expectations

- Input: batch of 5 `login_event` events with `auth.action = login_failed`, same host and user
- Expected: 1 alert with `rule_id = failed_login_burst`, `evidence.failed_count >= 3`
- Benign: single `login_event` with `auth.action = login_success` → no alert

---

## Rule 5: suspicious_dns_query

**rule_id:** `suspicious_dns_query`  
**version:** v1  
**title:** Suspicious DNS Query  
**severity:** medium  
**confidence:** 0.68

### Description

Detects DNS queries that exhibit characteristics of domain generation algorithm (DGA) traffic or other anomalous DNS patterns. Current detection signals: domain label length exceeding 40 characters (possible DGA), or digit density exceeding 40% (common in algorithmically generated domains). These are heuristics, not threat intelligence matches.

### ATT&CK Mapping

- T1071.004 — Application Layer Protocol: DNS
- T1568 — Dynamic Resolution
- T1568.002 — Domain Generation Algorithms

### Event Requirements

- Event type: `dns_query`
- `dns.domain` is non-empty
- Trigger condition (any of):
  - `len(domain) > 40` characters
  - Digit-to-total-character ratio > 0.40

### Evidence Fields

| Field | Description |
|---|---|
| `domain` | The queried domain |
| `reason` | Detection reason: `high_length_possible_dga` or `high_numeric_density` |
| `domain_length` | Total domain string length |

### False Positive Notes

- Legitimate CDN and cloud service domains (e.g., some AWS and Azure endpoints are very long)
- GUID-based service endpoints
- Some telemetry and crash-reporting domains

### Suppression Guidance

Suppress by domain (known long-but-legitimate domains), by process.name (known telemetry agents), or by resolved_ips (domains resolving to known vendor IP ranges).

### Replay Validation Expectations

- Input: `dns_query` with `dns.domain = randomlookingdomainc2beacon12345.dynamic.invalid` (48 chars)
- Expected: 1 alert with `rule_id = suspicious_dns_query`, `evidence.reason = high_length_possible_dga`
- Benign: `dns_query` with `dns.domain = example.com` → no alert

---

## Rule 6: suspicious_outbound_connection

**rule_id:** `suspicious_outbound_connection`  
**version:** v1  
**title:** Suspicious Outbound Network Connection  
**severity:** medium  
**confidence:** 0.65

### Description

Detects network connections from an endpoint to an external (non-RFC1918) IP address on a non-standard port. Common attack patterns include C2 communication on unusual ports (4444, 8443, 1337, etc.) to avoid detection. This rule fires on any external destination with a port not in the common service port allowlist.

### ATT&CK Mapping

- T1571 — Non-Standard Port
- T1095 — Non-Application Layer Protocol
- T1041 — Exfiltration Over C2 Channel

### Event Requirements

- Event type: `network_connection`
- `network.destination_ip` is non-empty and NOT in RFC1918 ranges (10.x.x.x, 172.16-31.x.x, 192.168.x.x, 127.x.x.x, 169.254.x.x)
- `network.destination_port` is non-zero and NOT in the common port allowlist: {22, 25, 53, 80, 110, 143, 443, 587, 993, 995, 3389, 8080, 8443}

### Evidence Fields

| Field | Description |
|---|---|
| `destination_ip` | External destination IP address |
| `destination_port` | Non-standard destination port |
| `protocol` | Network protocol |

### False Positive Notes

- Custom application ports (proprietary protocols on unusual ports)
- Gaming or VoIP applications using non-standard ports
- Peer-to-peer applications

### Suppression Guidance

Suppress by destination IP (known partner or vendor IPs), by port (add to allowlist for known services), or by process.name (known application using non-standard port for legitimate reasons).

### Replay Validation Expectations

- Input: `network_connection` to `185.220.101.200:4444/tcp` (external IP, non-standard port)
- Expected: 1 alert with `rule_id = suspicious_outbound_connection`
- Benign: `network_connection` to `93.184.216.34:443/tcp` (external IP, standard HTTPS port) → no alert
