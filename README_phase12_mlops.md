# Phase 12 - Minimal MLOps (Production-Defensible)

Phase ini menambah jawaban untuk pertanyaan: drift, retraining, audit, dan deployment lock.

## 1) Model Registry

Tabel baru:
- `ml_models`
- `ml_model_deployments`

Menyimpan:
- artifact path + `artifact_sha256`
- `training_data_start` / `training_data_end`
- `feature_hash`
- metrics + train config
- `git_commit`
- deployment metadata + lock flag

Register + deploy model:

```bash
python scripts/mlops_register_model.py \
  --model storage/app/ai_detector_model.pkl \
  --report storage/app/ai_detector_report.json \
  --dataset storage/app/security_dataset.csv \
  --deploy --env local --deployed-by thesis
```

## 2) Deployment Lock (No Silent Changes)

`scripts/realtime_detector_consumer.py` sekarang mendukung:
- resolve model dari `ml_model_deployments` aktif
- verifikasi hash artifact sebelum start
- fail-fast jika lock aktif dan hash mismatch

Env/arg penting:
- `ML_USE_ACTIVE_DEPLOYMENT=1`
- `ML_DEPLOYMENT_ENV=local`
- `ML_DEPLOYMENT_LOCK=1`
- `ML_ALLOWED_ARTIFACT_SHA256=<hash>` (opsional; biasanya dari deployment record)

## 3) Drift Monitoring

Script PSI drift monitor:

```bash
python scripts/mlops_drift_monitor.py \
  --dataset storage/app/security_dataset.csv \
  --env local \
  --lookback-hours 24 \
  --psi-threshold 0.25 \
  --output storage/app/ml_drift_report.json
```

Jika drift terdeteksi:
- script menulis `DRIFT_DETECTED` ke `security_alerts`
- evidence berisi feature PSI dan threshold

## 4) Retraining Policy

Policy checker:
- retrain jika deployment age >= 7 hari (weekly)
- atau jika drift report menunjukkan drift tinggi

Jalankan:

```bash
python scripts/mlops_retrain_policy.py \
  --env local \
  --weekly-days 7 \
  --drift-report storage/app/ml_drift_report.json \
  --output storage/app/ml_retrain_policy.json
```

Output:
- `retrain_required: true|false`
- alasan trigger (`weekly_schedule_due`, `drift_detected`)

## 5) Auditability Story (untuk presentasi)

- Model yang aktif dapat ditelusuri ke:
  - artifact hash
  - data range training
  - feature hash
  - metric report
  - commit id
- Drift tercatat sebagai alert (`DRIFT_DETECTED`)
- Deploy diam-diam dicegah oleh lock + hash check sebelum consumer start
