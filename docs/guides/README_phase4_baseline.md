# Phase 4: Baseline Rules Detector

This phase provides a non-AI baseline detector for academic comparison.

## Input

Labeled dataset CSV from Phase 2:

- `storage/app/security_dataset.csv`

## Run Baseline Detector

```bash
python scripts/baseline_rules_detector.py \
  --input storage/app/security_dataset.csv \
  --output storage/app/baseline_rules_report.json
```

## Implemented Rule Families

- `BRUTE_FORCE_IP`
- `CREDENTIAL_STUFFING`
- `SCAN_BURST`
- `INJECTION_INDICATOR`
- `PRIVILEGE_PROBING`

## Output

`baseline_rules_report.json` contains:

- confusion matrix (`normal`, `bruteforce`, `scan`, `injection`)
- per-class precision/recall/F1
- overall accuracy
- rule trigger counts
- thresholds used by the baseline
