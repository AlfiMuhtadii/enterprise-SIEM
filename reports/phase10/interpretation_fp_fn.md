# Phase 10 Interpretation (FP/FN)

- Best overall (macro F1): `hybrid`.
- `rules_only` biasanya kuat untuk burst pattern, tapi rawan miss low-and-slow dan varian payload baru.
- `ml_only` lebih adaptif ke variasi pola, tapi berpotensi FP pada noise traffic tinggi.
- `hybrid` menurunkan FN pada serangan obvious (karena rules override) sambil mempertahankan generalisasi ML.
- Fokus analisis Bab Hasil: bandingkan FP/FN per skenario (`low_and_slow`, `burst`, `high_noise_normal`, `new_injection_variant`).