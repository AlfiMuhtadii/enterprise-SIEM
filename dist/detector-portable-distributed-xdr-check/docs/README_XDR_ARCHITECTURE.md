# XDR Cross-Domain Architecture

This upgrade extends the platform from endpoint-aware SOC/EDR behavior toward XDR by adding normalized telemetry and correlation across email, identity, cloud, SaaS, firewall/proxy, DNS, and endpoint sources.

## Supported Cross-Domain Adapters

Use `scripts/telemetry_adapters.py` to normalize source logs into `telemetry_events` JSONL:

```bash
python scripts/telemetry_adapters.py --adapter m365-audit --input samples/real-world/xdr/m365_audit_email_identity_chain.jsonl --output storage/logs/xdr_email.jsonl
python scripts/telemetry_adapters.py --adapter m365-signin --input signin.jsonl --output storage/logs/xdr_identity.jsonl
python scripts/telemetry_adapters.py --adapter google-workspace-audit --input google_audit.jsonl --output storage/logs/xdr_saas.jsonl
python scripts/telemetry_adapters.py --adapter aws-cloudtrail --input cloudtrail.jsonl --output storage/logs/xdr_cloud.jsonl
python scripts/telemetry_adapters.py --adapter azure-signin --input azure_signin.jsonl --output storage/logs/xdr_identity.jsonl
python scripts/telemetry_adapters.py --adapter azure-audit --input azure_audit.jsonl --output storage/logs/xdr_cloud.jsonl
python scripts/telemetry_adapters.py --adapter firewall-proxy-jsonl --input proxy.jsonl --output storage/logs/xdr_proxy.jsonl
```

## XDR Normalized Fields

The `telemetry_events` table now supports optional XDR fields:

- `xdr_user`
- `xdr_host`
- `source_ip`
- `destination_ip`
- `domain`
- `file_hash`
- `email_sender`
- `email_recipient`
- `cloud_account`
- `xdr_action`
- `xdr_result`
- `risk_score`
- `event_source`

Existing endpoint/network/DNS ingestion remains compatible.

## XDR Correlation Rules

Run:

```bash
python scripts/xdr_correlation_detector.py --minutes=1440
```

The detector creates XDR alerts and expands incidents with:

- cross-domain evidence
- involved users
- involved hosts
- involved cloud accounts
- involved email artifacts
- involved external IPs/domains
- XDR kill-chain summary

Supported correlation patterns:

- phishing email -> suspicious login -> endpoint execution
- impossible login -> privilege change -> cloud access
- IOC hit -> DNS beacon/query -> endpoint alert
- proxy anomaly -> endpoint process -> incident escalation candidate

## Identity Detections

- `IDENTITY_IMPOSSIBLE_TRAVEL`
- `IDENTITY_UNUSUAL_LOGIN_SOURCE`
- `IDENTITY_MFA_FAILURE_BURST`
- `IDENTITY_PRIVILEGE_ESCALATION`
- `IDENTITY_RISKY_IP_LOGIN`
- `IDENTITY_FAILED_LOGIN_ACROSS_SERVICES`

## Cloud/SaaS Detections

- `CLOUD_UNUSUAL_API_ACTIVITY`
- `CLOUD_SUSPICIOUS_OBJECT_ACCESS`
- `CLOUD_MASS_DOWNLOAD`
- `CLOUD_NEW_ACCESS_KEY`
- `CLOUD_SECURITY_SETTING_MODIFIED`
- `SAAS_UNUSUAL_ADMIN_ACTIVITY`

## XDR Dashboard

The SOC dashboard now includes:

- cross-domain incidents
- identity risk
- cloud risk
- email threat activity
- SaaS activity
- firewall/proxy anomalies
- recent XDR evidence timeline
- telemetry mix by domain

## Validation

Sample labels are stored in:

```text
samples/real-world/xdr/xdr_validation_labels.json
```

Generate a validation readiness report:

```bash
python scripts/xdr_validation_report.py --output reports/xdr_validation_report.json
```

If you export detector alerts to JSONL, pass them into:

```bash
python scripts/xdr_validation_report.py --alerts reports/xdr_alerts.jsonl --output reports/xdr_validation_report.json
```
