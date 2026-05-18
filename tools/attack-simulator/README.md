# Detector Attack Simulator

Tool simulasi serangan untuk validasi pipeline SIEM/XDR. Menghasilkan events ke Redpanda sesuai XDR Event Contracts.

## Arsitektur

```
Scenario YAML → Engine → Event Builder → Producer → Redpanda Topics
```

## Quick Start

### 1. Build & Run dengan Docker Compose

```bash
docker compose --profile simulation up -d --build
```

### 2. Run Scenario Spesifik

```bash
# Brute Force RDP
docker exec detector-attack-simulator ./simulator --name brute-force-rdp

# Full APT Kill Chain (20 menit simulated time)
docker exec detector-attack-simulator ./simulator --name apt-killchain --speed 2.0

# Test ke stdout (tanpa Redpanda)
docker exec detector-attack-simulator ./simulator --name port-scan --output stdout
```

### 3. Monitor Events

```bash
# Lihat events di Redpanda
docker exec detector-redpanda rpk topic consume identity.events --num 10

# List semua topics
docker exec detector-redpanda rpk topic list
```

## Scenario Library

| Scenario | MITRE Technique | Events | Duration |
|----------|----------------|--------|----------|
| `brute-force-rdp` | T1110.001 | 52 identity + endpoint | ~2m |
| `port-scan` | T1046 | 1001 network | ~30s |
| `malware-dropper` | T1204.002 | 10 endpoint + network | ~15s |
| `lateral-movement` | T1021.002 | 12 identity + network + endpoint | ~10s |
| `cloud-iam-abuse` | T1098 | 5 cloud | ~2m |
| `dns-tunneling` | T1071.004 | 550 network | ~2m |
| `apt-killchain` | Multi-Stage | 127 mixed | ~20m |

## Menulis Scenario Baru

Buat file YAML di `scenarios/` dengan format:

```yaml
id: scenario-unique-id
name: "Nama Scenario"
description: "Deskripsi"
mitre:
  tactic: "Tactic Name"
  technique: "T####"
  technique_name: "Technique Name"
actor:
  profile: noisy | stealthy | apt
  jitter: 0.2
  source_ip_pool:
    - 10.0.0.100
timeline:
  - time: 0s
    action: generate_events
    source: identity | endpoint | network | cloud
    event_type: authentication.failure | process.creation | flow | dns.query | ...
    count: 50
    interval: 2s
    params:
      key: value
```

## Event Sources

| Source | Event Types | Topic |
|--------|-------------|-------|
| `identity` | authentication.failure, authentication.success | `identity.events` |
| `endpoint` | process.creation, file.creation | `endpoint.events` |
| `network` | flow, dns.query, port_scan.detected | `network.events` |
| `cloud` | iam.policy.change, login.anomaly | `cloud.events` |

## Speed Control

Gunakan flag `--speed` untuk mempercepat/memperlambat:
- `--speed 1.0` = real-time (default)
- `--speed 2.0` = 2x lebih cepat
- `--speed 0.5` = setengah kecepatan
- `--speed 10.0` = 10x cepat (untuk testing)

## Replayable Events

Semua events memiliki field `simulator: true` dan `event_id` unik, memungkinkan replay dan audit.
