# Alert Cardinality & Idempotency (Q&A Ready)

`security_alerts` row **bukan** “1 event = 1 alert”.

Satu event dapat menghasilkan beberapa alert karena desain:
- multi-detector output: rules + ML
- multi-rule hit pada actor/window sama (mis. `SCAN_BURST` + `ML_SCAN`)

Jadi rasio `alerts > events` bisa legitimate selama mapping ini konsisten dan deduplicated.

## Alert identity (idempotent)

`alert_id` dihitung deterministik:

`HMAC(schema_version|detector_version|alert_type|actor_key|window_start|window_end|threshold_profile_hash)`

Konsekuensi:
- replay event yang sama pada window yang sama tidak membuat row alert baru (ON CONFLICT DO NOTHING)
- offset reset/restart consumer tidak menggelembungkan data

## Alert row semantics

Setiap row merepresentasikan:
- satu `alert_type`
- satu `actor_key` (umumnya IP)
- satu `window_start/window_end`
- satu `detector_version`
- satu profile threshold (`threshold_profile_hash`)

## Proving query

Jalankan query berikut untuk bukti konsistensi:
- `scripts/sql/qa_alert_consistency.sql`

Terutama:
- `count(*) vs count(distinct alert_id)` (dedup check)
- grouped by `(alert_type, detector_name, detector_version)`
- average alerts per actor per window
