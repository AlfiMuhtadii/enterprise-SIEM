# Developer Demo Guide — End to End

Panduan praktis untuk developer/presenter menjalankan demo platform dari nol sampai live.
Bukan untuk AI — untuk manusia yang mau demo atau verifikasi environment.

---

## Prasyarat

| Kebutuhan | Cek |
|---|---|
| Docker Desktop running | `docker info` |
| PHP 8.1+ dengan ext-pgsql | `php -v` |
| Python 3.9+ | `python --version` |
| Node.js 18+ | `node --version` |
| Composer | `composer --version` |
| Port bebas | 8000 (Laravel), 5432 (Postgres), 9092 (Redpanda), 3000 (Grafana), 8080 (Redpanda Console) |

---

## Jalur Cepat (Full Reset dari Nol)

```powershell
# 1. Bootstrap — start infra + migrate + seed demo data
.\bootstrap-dev.ps1

# 2. Start Laravel di terminal baru
php artisan serve

# 3. Buka browser ke http://localhost:8000
# Login: admin / password (atau sesuai seeder)
```

Selesai. Platform sudah live dengan demo data.

---

## Jalur Presentasi Deterministik (Direkomendasikan untuk Demo/Sidang)

Script `scripts/final-present.ps1` menjalankan seluruh alur otomatis:

```powershell
# Full run (infra up + reset + scenario + verify + report)
powershell -ExecutionPolicy Bypass -File .\scripts\final-present.ps1

# Kalau infra sudah hidup dan tidak mau reset ulang
powershell -ExecutionPolicy Bypass -File .\scripts\final-present.ps1 -SkipUp -SkipReset
```

Script ini secara otomatis:
1. Bring up infra + migrate + seed (`demo_runbook.py up`)
2. Reset ke state deterministik (`demo_runbook.py reset`)
3. Jalankan serangan simulasi (phishing chain, LOLBin, brute-force)
4. Verifikasi assertions end-to-end (min events/alerts/responses)
5. Tampilkan alert summary (`php artisan security:alerts-report`)
6. Tampilkan pipeline health (`php artisan security:pipeline-health`)
7. Buka URL demo dashboard
8. Jalankan MLOps drift monitor
9. Jalankan retrain policy decision

**Fail-fast:** kalau satu step gagal, script langsung stop.

---

## URL Penting saat Demo

| Tampilan | URL |
|---|---|
| Login | http://localhost:8000/login |
| SOC Dashboard | http://localhost:8000/soc |
| **Demo Platform** (entry point demo) | http://localhost:8000/demo-platform |
| Security Alerts | http://localhost:8000/security/alerts |
| Incident List | http://localhost:8000/soc/incidents |
| Detection Rules | http://localhost:8000/soc/rules |
| Threat Hunting | http://localhost:8000/threat-hunts |
| Investigation Workflow | http://localhost:8000/investigations |
| Response Planning | http://localhost:8000/response-plans |
| XDR Maturity | http://localhost:8000/xdr-maturity |
| XDR Certification | http://localhost:8000/xdr-certification |
| Entity Graph | http://localhost:8000/entity |
| Endpoint Fleet | http://localhost:8000/endpoint-fleet |
| UEBA | http://localhost:8000/ueba |
| SOAR Orchestration | http://localhost:8000/soar |
| Scenario Runner | http://localhost:8000/scenario |
| Grafana | http://localhost:3000 (admin/admin) |
| Redpanda Console | http://localhost:8080 |

---

## Alur Demo Manual (5–15 menit)

### A. Tunjukkan Deteksi (3 menit)
1. Buka **Demo Platform** → Overview
2. Buka **Security Alerts** → filter by severity=HIGH
3. Klik satu alert → lihat evidence, MITRE mapping, trace_id
4. Tunjukkan bahwa endpoint/DNS/proxy masih shadow (tidak masuk active alerts)

### B. Tunjukkan Investigasi (3 menit)
1. Buka **Investigations** → buka investigation aktif
2. Lihat 8-state machine progress (open → analyzing → escalated → ...)
3. Buka **Entity Graph** → cari entity dari actor_key
4. Tunjukkan relationship graph antar entity

### C. Tunjukkan Response (2 menit)
1. Buka **Response Plans** → lihat rekomendasi advisory-only
2. Tunjukkan bahwa semua action adalah `recommend_*` — tidak ada `execute_*`
3. Buka **SOAR** → lihat dual-approval gate, simulation-first

### D. Tunjukkan Architecture (3 menit)
1. Buka **XDR Maturity** → capability matrix
2. Buka **XDR Certification** → readiness score, open risks
3. Terminal: `php artisan security:alerts-report --minutes=15`
4. Terminal: `python scripts/xdr_rule_registry_validate.py`

### E. Tunjukkan Threat Hunting (2 menit)
1. Buka **Threat Hunting** → New Hunt
2. Pilih domain (e.g., `endpoint_behavioral_findings`)
3. Jalankan query → lihat hasil advisory-only, append-only

---

## Reset Demo State (tanpa restart infra)

```powershell
# Reset data + re-seed (idempotent, replay-safe)
.\bootstrap-dev.ps1 -Reset

# Atau manual:
php artisan migrate:fresh --force
php artisan db:seed --class=DemoScenarioSeeder
```

---

## Verifikasi Environment Sebelum Demo

```powershell
# 1. Semua test harus hijau
php artisan migrate:fresh --force && php artisan test
# → 3077 passed, 0 failures

# 2. Rule registry harus PASS
python scripts/xdr_rule_registry_validate.py
# → PASS rules=133 checks=21/21

# 3. Python agent tests
python -m unittest discover -s tests/endpoint_agent -p "test_*.py" -v
# → 186 passed

# 4. Docker config valid
docker compose config --quiet
# → exit code 0

# 5. Secret validation
php artisan security:validate-secrets
```

---

## Stop / Teardown

```powershell
# Stop infra tapi data dipertahankan di Docker volumes
.\bootstrap-dev.ps1 -Teardown

# Stop + hapus semua data
docker compose down -v
```

---

## Troubleshooting

| Masalah | Solusi |
|---|---|
| `QueryException` saat test | Jalankan `php artisan migrate:fresh --force` dulu |
| SOC buttons tidak muncul | `Gate::before()` di `AuthServiceProvider` harus ada |
| Alert tidak muncul | Cek `XDR_CORRELATION_ENGINE=go` dan `XDR_CORRELATION_SCOPE=identity-cloud` di `.env` |
| Redpanda tidak bisa connect | `docker compose up -d redpanda` lalu tunggu healthcheck |
| Port 8000 sudah dipakai | Ganti dengan `php artisan serve --port=8001` dan update `BASE_URL` |
| OpenSearch lambat start | Tunggu 60 detik — sudah ada `start_period: 60s` di docker-compose |

---

## File Referensi untuk Slides / Q&A

| File | Isi |
|---|---|
| `docs/THESIS_DEFENSE_GUIDE.md` | Panduan sidang + Q&A akademik |
| `docs/FINAL_CAPABILITY_MATRIX.md` | Matrix kapabilitas (implemented/advisory/shadow/not_implemented) |
| `docs/KNOWN_LIMITATIONS.md` | 10 limitasi yang terdokumentasi |
| `docs/DEMO_WALKTHROUGH.md` | 4 track demo (A/B/C/D) |
| `docs/QUICKSTART_EVALUATOR.md` | Setup 15 menit untuk evaluator |
| `docs/INTERVIEW_SHOWCASE_GUIDE.md` | Format 5 menit dan 15 menit |
| `docs/guides/README_phase12_mlops.md` | MLOps pipeline detail |
| `docs/guides/README_phase13_threat_model.md` | Threat model |
| `docs/guides/README_phase14_response.md` | Response framework |
| `reports/phase10/report.json` | ML evaluation metrics |
