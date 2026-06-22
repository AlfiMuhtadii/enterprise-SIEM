# Q&A Defense Pack (Skripsi)

## 1) Bagaimana memastikan model AI tidak overfitting?
Jawaban singkat:
- Split data kami **by attack_run**, bukan random row, jadi tidak ada leakage antar train-test.
- Train dan test memakai run berbeda (contoh `bruteforce_run_1` untuk train, `bruteforce_run_2` untuk test).
- Kami uji di kondisi **noise traffic**, **burst vs low-slow**, dan **varian payload baru**.

Kalimat kunci:
- "Model diuji pada run serangan yang tidak dipakai saat training."

Red flag yang harus dihindari:
- Jangan bilang "akurasi tinggi" tanpa jelaskan split by run.

## 2) Kenapa pakai AI kalau rule-based sudah cukup?
Jawaban singkat:
- Rules kuat untuk signature attack yang jelas.
- ML kuat untuk pola perilaku yang tidak rigid threshold.
- Kami bandingkan `rules-only`, `ml-only`, `hybrid`; hybrid memberi tradeoff terbaik precision/recall.

Contoh kuat:
- low-rate credential stuffing sering lolos rule rate-threshold, tapi masih bisa tertangkap ML dari kombinasi fitur.

Red flag:
- Jangan klaim AI selalu lebih baik; tekankan **komplementer** dengan rules.

## 3) Bagaimana skalabilitas ke produksi?
Jawaban singkat:
- Pipeline event-driven: App -> Stream (Redpanda) -> Detector Workers -> ClickHouse -> Grafana/Response.
- Scale lewat partition stream + horizontal worker + analytical storage.
- Sistem ini **near-real-time**, bukan inline blocking.

Red flag:
- Jangan sebut “realtime 0 ms”. Gunakan istilah near-real-time (detik-level).

## 4) False positive ditangani bagaimana?
Jawaban singkat:
- severity tier, allowlist, suppression, audited threshold tuning, dan hybrid scoring rules+ML.

Kalimat kunci:
- "Kontrol FP adalah bagian desain, bukan patch belakangan."

## 5) Apakah ini menggantikan firewall/WAF?
Jawaban singkat:
- Tidak. Ini layer deteksi perilaku (IDS/UEBA app-level), bukan pengganti firewall/WAF.

Layering:
- Firewall: network control
- WAF: request filtering
- IDS/UEBA: behavior detection + correlation

## 6) Privasi user bagaimana dijaga?
Jawaban singkat:
- email/UA di-HMAC, tidak simpan raw password/email/payload sensitif.
- retention policy diterapkan (raw events lebih pendek, alert lebih lama).

## 7) Bukti teknis yang ditunjukkan saat sidang
- `python scripts/demo_runbook.py verify ...` (hard assertions pass)
- `php artisan security:alerts-report --minutes=15`
- `reports/phase10/report.json` (evaluasi rules vs ML vs hybrid)
- `storage/app/ml_drift_report.json` + `storage/app/ml_retrain_policy.json`
- `README_phase13_threat_model.md`

## 8) Struktur jawaban 30 detik (template)
1. Prinsip desain (apa dan kenapa)
2. Bukti eksperimen/operasional
3. Batasan sistem (jujur)
4. Mitigasi lanjutan/roadmap
