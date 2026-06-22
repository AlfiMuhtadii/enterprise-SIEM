# Phase 9 - End-to-End Validation & Data Contract

Phase ini memastikan klaim deteksi bisa dibuktikan secara repeatable.

## 1) Schema Contract + Versioning

- Event generator sekarang menulis `schema_version: 1`.
- Validator contract: `scripts/security_event_contract.py`.
- Rule utama validator:
  - wajib: `schema_version`, `ts`, `event|event_type`, `request_id`, `ip`, `user_agent_hash`, `method`, `path`, `status`
  - tipe hash (`user_agent_hash`, `email_hash`, `query_hash`) = hex 64
  - `request_id` harus UUID
  - `status` harus 100..599

## 2) Enforcement Runtime

- Ingestion: `scripts/ingest_security_events.py`
  - event invalid di-drop
  - counter `Invalid` + `InvalidSchema` dicetak
- Consumer realtime: `scripts/realtime_detector_consumer.py`
  - event invalid di-drop
  - counter `invalid_events_dropped` dicetak

## 3) Golden-Run Replay

Dataset replay disimpan di:
- `scripts/golden_runs/normal.jsonl`
- `scripts/golden_runs/bruteforce.jsonl`
- `scripts/golden_runs/scan.jsonl`
- `scripts/golden_runs/injection.jsonl`

Baseline ekspektasi:
- `scripts/golden_runs/expected_replay.json`

Replay tool:
- `scripts/golden_replay_test.py`

## 4) Cara Jalankan Validasi

Validate schema:

```bash
python scripts/security_event_contract.py --file scripts/golden_runs/normal.jsonl
python scripts/security_event_contract.py --file scripts/golden_runs/bruteforce.jsonl
python scripts/security_event_contract.py --file scripts/golden_runs/scan.jsonl
python scripts/security_event_contract.py --file scripts/golden_runs/injection.jsonl
```

Replay determinism (rules + ML stability):

```bash
python scripts/golden_replay_test.py --output storage/app/golden_replay_report.json
```

Output report:
- `storage/app/golden_replay_report.json`

Kriteria lulus:
- `comparison.ok = true`
- rules counts sama persis dengan baseline
- `ml_signature` konsisten terhadap baseline (untuk model yang sama)

## 5) CI

Workflow CI ditambahkan:
- `.github/workflows/phase9-contract.yml`

CI menjalankan:
1. contract validation untuk 4 golden dataset
2. replay determinism test terhadap baseline
