# Phase 10 - Evaluation Harness (Skripsi Valid)

Harness evaluasi ada di:
- `scripts/evaluate_phase10.py`

## Tujuan yang dicakup
- Split dataset berdasarkan `attack_run` (anti leakage)
- Bandingkan 3 baseline:
  - rules-only
  - ml-only
  - hybrid (rules override, fallback ke ML)
- Hitung:
  - precision/recall/F1 per kelas
  - confusion matrix
  - ROC-AUC one-vs-rest
  - detection latency (dari start `attack_run` ke alert pertama)
- Stress experiments:
  - low-and-slow vs burst
  - high noise traffic
  - new injection variant (run injection yang tidak ada di train)

## Jalankan

```bash
python scripts/evaluate_phase10.py \
  --input storage/app/security_dataset.csv \
  --output-dir reports/phase10
```

## Output (folder laporan)

Folder: `reports/phase10`

- `report.json` (ringkasan lengkap)
- `metrics_summary.csv`
- `per_class_metrics.csv`
- `roc_auc_ovr.csv`
- `detection_latency.csv`
- `stress_experiments.csv`
- `confusion_rules_only.csv`
- `confusion_ml_only.csv`
- `confusion_hybrid.csv`
- `fp_fn_rules_only.csv`
- `fp_fn_ml_only.csv`
- `fp_fn_hybrid.csv`
- `graph_accuracy.svg`
- `graph_macro_f1.svg`
- `graph_latency_first_alert.svg`
- `interpretation_fp_fn.md`
