# Reference — OSINT, Attribution, and False Positive Reduction

## 1. Goal

Tujuan fitur ini untuk membangun **evidence-based attribution hypothesis** secara otomatis.

Sistem mengumpulkan bukti dari telemetry internal, threat intelligence, dan OSINT untuk membantu analyst menjawab:

```txt
- kemungkinan sumber serangan
- infrastruktur yang dipakai
- campaign atau pola serangan
- alias/username candidate
- confidence level
- evidence trail
```

Output sistem harus memakai istilah:

```txt
possible
likely
candidate
hypothesis
confidence
evidence
```

Jangan memakai klaim absolut seperti:

```txt
confirmed attacker identity
hacker pasti orang ini
```

---

## 2. Core Principle

OSINT tidak boleh menjadi sumber kebenaran utama.

Urutan evidence yang lebih kuat:

```txt
1. Internal telemetry correlation
2. Source IP / ASN / hosting / VPN / proxy context
3. Event sequence and behavior pattern
4. Payload fingerprint
5. User-agent / JA3 / JA4 / TLS fingerprint
6. Historical sightings inside this platform
7. Threat intelligence reputation
8. Domain / URL / hash relationship
9. OSINT username / alias matches
```

Username/alias OSINT hanya berada di layer paling bawah. Ia bukan alat utama untuk mengurangi false positive attribution.

---

## 3. Recommended Architecture

OSINT dan attribution harus menjadi enrichment subsystem, bukan bagian dari hot path detection.

Correct flow:

```txt
Alert / Incident Created
        ↓
Entity Extractor
        ↓
Async Enrichment Queue
        ↓
Threat Intel + OSINT Workers
        ↓
Evidence Store
        ↓
Attribution Confidence Scorer
        ↓
SOC UI / Incident Timeline / Report Generator
```

Jangan taruh OSINT lookup di:

```txt
- normalizer hot path
- correlation hot path
- alert-writer critical insert path
- incident-builder critical path
```

Alasannya:

```txt
- OSINT lambat
- external service flaky
- rate limit bisa terjadi
- false positive tinggi
- tidak boleh menghambat core detection pipeline
```

---

## 4. Tool Positioning

### Maigret

Use case:

```txt
username / alias lookup across 500+ platforms
```

Cocok untuk:

```txt
- darkfox77
- xploitfox
- r4v3n_id
- handle unik lain
```

Tidak cocok untuk:

```txt
- admin
- root
- test
- guest
- user
- administrator
```

Output harus diperlakukan sebagai:

```txt
investigative lead
```

Bukan:

```txt
confirmed identity
```

Risk:

```txt
username sama ≠ orang sama
```

Note: Maigret dipilih sebagai satu-satunya username enrichment adapter. Sherlock dihapus karena
redundan — Maigret mencakup lebih banyak platform (500+), async, lebih aktif di-maintain,
dan memiliki confidence scoring per result.

---

### SpiderFoot

Lebih cocok untuk OSINT automation yang lebih luas.

Cocok untuk:

```txt
- IP
- domain
- email
- username
- ASN
- netblock
- DNS
- leak/reference
```

Catatan: SpiderFoot dapat menghasilkan banyak noise. Pilih modul secara selektif.
Gunakan mode targeted, bukan full scan.

Status:

```txt
recommended OSINT automation adapter (optional, targeted mode only)
```

---

### Shodan / Censys

**Gap yang sebelumnya tidak ada di plan. Prioritas tinggi.**

Use case:

```txt
source_ip / domain → open ports, running services, tech stack,
                     TLS certificate, banner, hosting history
```

Cocok untuk:

```txt
- membuktikan IP bukan innocent VPS
- port C2-typical (4444, 9001, 8080) terdeteksi
- layanan tidak umum terdeteksi di IP attacker
- infrastruktur pivot point analysis
```

Shodan InternetDB tersedia gratis tanpa API key:

```txt
https://internetdb.shodan.io/{ip}
```

Posisi di confidence chain: Layer 2 (Source IP / infrastructure context), setara AbuseIPDB
dan lebih informatif untuk memahami hosting infrastruktur attacker.

Status:

```txt
high priority — infrastructure fingerprinting
```

---

### GreyNoise

Sangat cocok untuk mengurangi false positive dari internet scanner/noise.

Use case:

```txt
source_ip → scanner/noise/malicious context
```

Cocok untuk:

```txt
- port scan
- exploit spray
- SSH brute force umum
- WordPress scan
- mass internet scanner
```

Status:

```txt
high priority for false positive reduction
```

---

### ipinfo.io / BGPView

**Gap yang sebelumnya tidak ada di plan.**

Use case:

```txt
IP → ASN owner, hosting provider, VPN/proxy/Tor detection, country
```

Cocok untuk:

```txt
- membedakan bullet-proof hoster vs IP rumahan
- mendeteksi VPN/proxy komersial
- ASN reputation context
- menghubungkan IP ke organisasi hosting
```

ipinfo.io tersedia gratis hingga 50k requests/bulan.

Status:

```txt
high priority — ASN / hosting context (ringan, gratis)
```

---

### MISP Warninglists

Cocok untuk mencegah IOC umum/benign langsung dianggap malicious.

Use case:

```txt
indicator matches warninglist → lower confidence, do not auto-escalate
```

Contoh:

```txt
- CDN
- public DNS
- cloud provider ranges
- known benign infrastructure
- internal scanner ranges
```

Status:

```txt
high priority for IOC false positive reduction
```

---

### MISP / OpenCTI

Cocok untuk threat intelligence context.

MISP:

```txt
IOC sharing, warninglists, sightings, internal intel
```

OpenCTI:

```txt
knowledge graph: indicator → malware → campaign → actor → technique → observable
```

Status:

```txt
recommended for medium/long-term threat intelligence layer
```

---

### AlienVault OTX

**Gap yang sebelumnya tidak ada di plan.**

Use case:

```txt
IP, domain, URL, file hash → pulse correlation, malware family, campaign tag
```

Berbeda dengan MISP (self-hosted), OTX adalah cloud-based dan siap pakai tanpa infrastruktur
tambahan. Gratis dengan API key.

Cocok sebagai:

```txt
- complement MISP sebelum infrastruktur MISP self-hosted siap
- community-driven threat intel untuk campaign tagging
- cross-reference malware family pada hash
```

Status:

```txt
recommended — free community threat intel (complement MISP)
```

---

### VirusTotal / AbuseIPDB / URLhaus / urlscan

Use case:

```txt
external reputation enrichment
```

Roles:

```txt
VirusTotal  → file/hash/domain/IP relationship and detection ratio
AbuseIPDB   → IP abuse confidence and reports
URLhaus     → malware URL intelligence (abuse.ch ecosystem)
urlscan     → URL behavior, redirects, page/resource analysis
```

Status:

```txt
use as enrichment evidence, not final verdict
```

Wrong logic:

```txt
if AbuseIPDB score > 0 then malicious
```

Correct logic:

```txt
if multiple independent sources agree
and local telemetry supports it
and not matched by warninglist
then increase confidence
```

---

### MalwareBazaar

**Gap yang sebelumnya tidak ada di plan.**

Use case:

```txt
file_hash → malware family, first seen, tags, YARA rules
```

Cocok untuk:

```txt
- enrich file_hash entity yang tidak cover oleh URLhaus
- menentukan malware family dari hash
- lebih cepat dan spesifik vs VirusTotal untuk hash lookup
```

Bagian dari abuse.ch ecosystem (sama dengan URLhaus). API gratis, ada Python client resmi.

Status:

```txt
recommended — file hash enrichment (complement URLhaus)
```

---

### JA3er / FoxIO JA4 Database / crt.sh / ViewDNS

**Gap yang sebelumnya tidak ada di plan. Diperlukan karena JA3/JA4 sudah ada sebagai entity type.**

| Entity | Tool | Keterangan |
|---|---|---|
| JA3 fingerprint | ja3er.com | Community database, free |
| JA4 fingerprint | FoxIO JA4 Database | Maintainer JA4 standard sendiri |
| TLS certificate | crt.sh | Certificate Transparency logs, free |
| Historical WHOIS | ViewDNS.info | Domain history, free |
| Passive DNS | CIRCL Passive DNS | DNS history, free |

Tanpa adapter ini, field `ja3`, `ja4`, `tls_certificate_fingerprint`, `reverse_dns`
di entity extractor hanya jadi data kosong yang tidak bisa dienrich.

Status:

```txt
required — specialized fingerprint and DNS enrichment
```

---

## 5. Recommended Module Name

Use:

```txt
BACKLOG-ATTRIBUTION-029 Threat Attribution Evidence & OSINT Enrichment
```

Avoid names like:

```txt
Hacker Tracker
Attacker Identity Finder
Doxxing Engine
```

Correct product framing:

```txt
SOC investigation assist
Threat attribution hypothesis
Evidence-based enrichment
```

---

## 6. Suggested Components

```txt
# Core
EntityExtractorService
AttributionEvidenceStore
AttributionConfidenceScorer
AnalystFeedbackService
OsintAuditEventService

# Adapter interface
ThreatIntelAdapter (interface semua adapter implement)

# Infrastructure & IP context (Layer 2)
ShodanAdapter
IpInfoAdapter
GreyNoiseAdapter
AbuseIPDBAdapter

# False positive reduction
MispWarninglistAdapter

# Threat intelligence
AlienVaultOTXAdapter
VirusTotalAdapter
UrlhausAdapter
MalwareBazaarAdapter
UrlscanAdapter

# Fingerprint & DNS enrichment
Ja3erAdapter
FoxIOJa4Adapter
CrtShAdapter
ViewDnsAdapter
CirclPassiveDnsAdapter

# Broader OSINT automation
SpiderFootAdapter (optional, targeted mode)

# Threat intel platform
OpenCTI / MISP (medium/long-term)

# Username enrichment (Layer 9 — lowest priority)
MaigretAdapter

# SOC UI
SOC UI Enrichment Panel
Report Generator Integration
```

Sherlock dihapus — redundan dengan Maigret.

---

## 7. Suggested Database Tables

```txt
attribution_entities
attribution_enrichment_runs
attribution_evidence
attribution_confidence_scores
attribution_sightings
attribution_analyst_verdicts
attribution_audit_events
```

Optional:

```txt
osint_raw_artifacts
threat_intel_cache
entity_relationships
```

Important:

```txt
Do not store sensitive personal OSINT without retention, audit, and RBAC controls.
```

---

## 8. Entity Types to Extract

From alert/incident telemetry:

```txt
source_ip
destination_ip
domain
url
email
username
user_agent
ja3
ja4
file_hash
process_name
command_line
wallet_address
asn
reverse_dns
tls_certificate_fingerprint
payload_fingerprint
```

Mapping entity ke adapter:

```txt
source_ip          → Shodan, GreyNoise, AbuseIPDB, ipinfo, AlienVaultOTX, VirusTotal
domain             → VirusTotal, urlscan, crt.sh, ViewDNS, CirclPassiveDns, AlienVaultOTX
url                → URLhaus, urlscan, VirusTotal
file_hash          → VirusTotal, MalwareBazaar, AlienVaultOTX
username           → Maigret (hanya jika username unik/non-generic)
ja3                → ja3er.com
ja4                → FoxIO JA4 Database
tls_certificate    → crt.sh
asn                → ipinfo, BGPView
email              → SpiderFoot (optional), AlienVaultOTX
```

Untuk Maigret, hanya kirim username yang unik dan non-generic:

```txt
kirim    : darkfox77, xploitfox, r4v3n_id
jangan   : admin, root, test, guest, user, administrator
```

---

## 9. Confidence Scoring Draft

Base score:

```txt
50
```

Increase confidence:

```txt
+25 same source IP seen in repeated attacks (internal telemetry)
+20 same payload fingerprint across multiple events
+20 malicious reputation from 2+ independent intel sources
+15 same user-agent / JA3 / JA4 pattern
+15 linked domain/hash/URL match
+15 local asset impact confirmed
+15 Shodan shows C2-typical ports/services on attacker IP
+10 IP in known malicious ASN / bullet-proof hosting
+10 username alias appears in OSINT (non-generic)
+10 historical sighting in same tenant
```

Decrease confidence:

```txt
-30 username is generic: admin/root/test/guest
-25 source is Tor/VPN/proxy with no other evidence
-25 MISP warninglist match (CDN/cloud/public DNS)
-20 GreyNoise benign/noise classification with no local impact
-20 only one weak OSINT match
-20 no local impact confirmed
-15 known internal scanner or maintenance window
-10 IP from major cloud provider with no other signal (shared infra)
```

Output bands:

```txt
0–39   weak / likely noise
40–59  investigative lead
60–79  likely same actor/campaign
80–100 high-confidence attribution hypothesis
```

Even at 100 — output is attribution hypothesis, not legal identity proof.

---

## 10. SOC Output Example

Good output:

```txt
Attribution Hypothesis

Infrastructure:
- Source IP belongs to hosting/VPN ASN (ipinfo)
- Shodan: ports 4444, 9001 open — C2-typical services
- Previously seen in similar brute-force activity (GreyNoise)

Campaign:
- Same payload fingerprint observed across 3 alerts
- Same JA3 fingerprint: linked to known malware family (ja3er)
- Same user-agent and timing pattern

Threat Intel:
- IP flagged by AlienVault OTX pulse: "APT-campaign-2026"
- File hash matched in MalwareBazaar: Cobalt Strike beacon

Alias Candidate:
- Username "darkfox77" found in public OSINT sources (Maigret)
- Confidence: Low/Medium
- Warning: username match is not identity proof

Overall Confidence:
- High (82/100)

Recommended Action:
- Treat as high-confidence investigative lead
- Attach evidence to incident
- Do not assert confirmed identity
```

Bad output:

```txt
Attacker is John Doe.
```

---

## 11. Safety and Governance Rules

Allowed:

```txt
- enrich alerts with public evidence
- show confidence score
- preserve evidence trail
- help analyst prepare abuse report
- help classify campaign/infrastructure
- store audit logs for enrichment access
```

Forbidden:

```txt
- claim real-world identity without proof
- doxxing
- harassment
- hack-back
- automated retaliation
- active scanning of personal accounts
- storing sensitive personal data without controls
```

---

## 12. Implementation Priority

Recommended order:

```txt
1.  Attribution Evidence Model (schema + entity extractor)
2.  Internal Sighting Correlation
3.  MISP Warninglist (false positive reduction — no external call)
4.  GreyNoise + Shodan/InternetDB + ipinfo (passive IP/infrastructure context)
5.  AbuseIPDB + VirusTotal + URLhaus + MalwareBazaar + AlienVault OTX
6.  urlscan + crt.sh + JA3er/JA4 + ViewDNS + CirclPassiveDns (specialized)
7.  Confidence Scorer
8.  Analyst Feedback Loop
9.  SOC UI Enrichment Panel
10. Maigret username enrichment (lowest priority — layer 9)
11. SpiderFoot (optional, targeted)
12. OpenCTI / MISP platform (medium/long-term)
13. Privacy, retention, and audit controls
```

Do not start with Maigret as the main system.

Shodan InternetDB (step 4) diprioritaskan lebih awal karena:

```txt
- gratis tanpa API key
- bukan active scan (data sudah dikumpulkan Shodan sebelumnya)
- aman secara operasional
- memberikan infrastructure context yang sangat kuat
```

---

## 13. Best First Backlog

```txt
BACKLOG-ATTRIBUTION-029 Threat Attribution Evidence & OSINT Enrichment
```

Initial scope should be limited:

```txt
- schema for attribution evidence
- entity extractor
- confidence scorer
- internal sighting correlation
- warninglist/reputation adapter interface
- GreyNoise + Shodan InternetDB + ipinfo adapters
- AbuseIPDB + VirusTotal adapters
- MalwareBazaar adapter
- Maigret adapter stub (optional worker, not in hot path)
- no blocking calls in detection hot path
- analyst-facing evidence output
```

Out of scope for first implementation:

```txt
- automated identity conclusion
- automated enforcement/remediation
- hack-back
- aggressive crawling
- real-time OSINT in correlation path
- SpiderFoot full scan
```

---

## 14. Final Decision

Correct conclusion:

```txt
Maigret         = username/alias enrichment (layer 9, lowest priority)
Shodan          = infrastructure fingerprinting (layer 2, high priority) — ADDED
ipinfo.io       = ASN/hosting/VPN context (layer 2, lightweight) — ADDED
SpiderFoot      = broader OSINT automation (optional, targeted mode)
GreyNoise       = scanner/noise filtering (false positive reduction)
MISP warninglists = IOC false positive reduction (no external call)
AlienVault OTX  = free community threat intel (complement MISP) — ADDED
MalwareBazaar   = file hash enrichment (complement URLhaus) — ADDED
JA3er/JA4/crt.sh = fingerprint + certificate enrichment — ADDED
OpenCTI/MISP    = threat intelligence graph (medium/long-term)
Internal telemetry correlation = strongest attribution evidence (always)
Confidence scoring + analyst review = required guardrail (always)
```

Sherlock dihapus — redundan dengan Maigret.

Therefore, the system implements an **evidence-based attribution layer** —
a threat attribution hypothesis engine, not an identity tracker.
