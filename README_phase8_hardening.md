# Phase 8 - Hardening & Q&A Pack

Dokumen ini adalah 1 halaman **Design Decisions & Tradeoffs** untuk bahan slide presentasi.

## Scope Sistem
- **In scope**: telemetry HTTP app, ingestion pipeline, rules + ML scoring, alerting, dashboard.
- **Out of scope**: deteksi murni network layer **L3/L4** (butuh sensor jaringan seperti Zeek/Suricata/NetFlow).

## Design Decisions & Tradeoffs

| Area | Decision | Why (Enterprise Rationale) | Tradeoff | Mitigasi |
|---|---|---|---|---|
| Privacy | Semua identifier sensitif di-hash dengan `HMAC-SHA256` (`app.key`) | Mencegah raw PII di log, lebih tahan rainbow-table dibanding hash biasa | Investigasi manual lebih sulit (tidak ada raw email/UA) | Gunakan korelasi via `request_id`, `event_id`, dan re-identification hanya di sistem IAM terpisah |
| Payload Hygiene | Tidak simpan raw payload berbahaya (`q`, body login), hanya `query_hash` + flags (`has_sql_keywords`, `has_script_payload`) | Kurangi risiko kebocoran data dan XSS payload di storage observability | Forensik konten payload tidak lengkap | Simpan indikator terstruktur + contoh sintetis dari simulator |
| Retention | TTL raw events lebih pendek, alerts lebih panjang | Sejalan prinsip minimization + cost control + kebutuhan audit alert | Histori event detail lama hilang | Snapshot agregat harian dan backup report berkala |
| Ingestion Semantics | At-least-once ingestion + dedup by deterministic `event_id` | Pipeline streaming realistis: duplicate bisa terjadi, event tidak hilang | Kompleksitas dedup dan state management | `ON CONFLICT DO NOTHING`, unique index, replay aman |
| Dedup Key | `event_id` dari field stabil (`ts|request_id|event_type|path|ip`) | Idempotent re-ingestion lintas retry/restart | Jika field berubah format, hash drift | Standarisasi canonicalization timestamp dan schema contract |
| False Positive Control | Severity tier (`low/medium/high/critical`) + allowlist + suppression window | Operasional SOC butuh signal-to-noise ratio tinggi | Risiko false negative kalau suppression terlalu agresif | Review berkala allowlist, expiry policy, audit trail alasan suppression |
| Explainability | Rule-based baseline tetap dipertahankan walau ada ML | Mudah dijelaskan ke audiens/assessor, jadi pembanding akademik | Recall terhadap pola baru bisa terbatas | Hybrid: rules untuk deterministic pattern, ML untuk pola kompleks |
| Local-First Enterprise Story | Local stack (Postgres/Redpanda/ClickHouse/Grafana) dengan arsitektur production-like | Demo stabil, reproducible, tetap scalable conceptually | Tidak sepenuhnya setara produksi (HA, multi-AZ, IAM) | Nyatakan batasan + roadmap hardening produksi (TLS, RBAC, secrets manager) |

## Operational Policies (Untuk Q&A)
- **Data classification**: security telemetry = restricted internal, bukan public analytics.
- **Access control**: dashboard/read-only untuk viewer; write path hanya detector/ingester.
- **Config hardening**: secret di `.env`, bukan hardcoded; rotate key/credential berkala.
- **Auditability**: setiap alert menyimpan `rule_hits`, score, dan `request_id` untuk traceability.

## Known Limits (Jawaban Jujur Saat Ditanya)
- Sistem ini mendeteksi **indikasi** serangan aplikasi, bukan membuktikan compromise host.
- Tanpa network sensor, anomali L3/L4 (SYN flood, port scan jaringan internal) tidak terlihat.
- Model ML bergantung pada kualitas labeling simulator; perlu data dunia nyata untuk generalisasi.

## Next Hardening (Jika ditanya roadmap lanjut)
1. Tambah WAF/network telemetry agar coverage naik ke L3/L4 + L7.
2. Terapkan TLS + auth antar service (producer, broker, detector).
3. Tambah model drift monitoring dan re-training pipeline periodik.
