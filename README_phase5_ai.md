# Phase 5: AI Model (Supervised)

This phase trains and evaluates a supervised ML detector for thesis experiments.
Implementation is dependency-free (pure Python logistic regression) to run offline.

## 1) Dependencies

No additional ML package is required for the core training script.

## 2) Generate richer attack runs (recommended)

```bash
php artisan sim:scenario --base-url=http://127.0.0.1:8000 --rounds=2 --profile=balanced
python scripts/ingest_security_events.py --from-start
php artisan security:export-dataset --output=storage/app/security_dataset.csv
```

This produces mixed `burst` + `low-and-slow` + `new scan pattern` runs for better generalization testing.

## 3) Train + evaluate

### Recommended baseline (logistic regression, split by run)

```bash
python scripts/train_ai_detector.py \
  --input storage/app/security_dataset.csv \
  --model logreg \
  --split-mode run \
  --train-ratio 0.7 \
  --output-model storage/app/ai_detector_model.pkl \
  --output-report storage/app/ai_detector_report.json \
  --output-predictions storage/app/ai_detector_predictions.csv
```

### Alternative split (time-based)

```bash
python scripts/train_ai_detector.py \
  --input storage/app/security_dataset.csv \
  --model logreg \
  --split-mode time
```

Note:

- `--model rf` is accepted for compatibility but currently falls back to logistic regression in offline mode.

## 4) Deliverables produced

- `storage/app/ai_detector_model.pkl` (model artifact)
- `storage/app/ai_detector_report.json` (metrics + analysis)
- `storage/app/ai_detector_predictions.csv` (test predictions)

## 5) Metrics in report

- Precision/Recall/F1 per class (`normal`, `bruteforce`, `scan`, `injection`)
- Overall accuracy
- Confusion matrix
- Detection latency:
  - first attack prediction since run start
  - first correct-class prediction since run start
- Scenario checks:
  - low-and-slow
  - burst
  - scan generalization on `/scan/*`
- FP/FN analysis with sample records
