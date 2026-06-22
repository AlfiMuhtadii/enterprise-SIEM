# Runbook: Mengetes Serangan Langsung per Jenis Serangan

Dokumen ini menjelaskan cara menguji deteksi serangan dengan traffic HTTP nyata ke aplikasi Laravel lokal. Pengujian ini tidak insert dummy alert langsung ke database. Request dikirim ke aplikasi, dicatat oleh middleware logging, lalu diproses oleh detector.

Gunakan hanya pada sistem lokal atau sistem yang memang Anda miliki/diizinkan untuk diuji.

## 1. Persiapan Wajib

Terminal 1:

```powershell
docker compose up -d
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSocSeeder
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Cek aplikasi:

```text
http://127.0.0.1:8000/login
```

Login SOC:

```text
soc-admin@example.com
password
```

Dashboard:

```text
http://127.0.0.1:8000/soc
```

## 2. Cara Paling Stabil: Attack Lab dengan Replay Detector

Mode ini paling mudah untuk demo karena tool akan:

1. mengirim request HTTP nyata ke Laravel,
2. ingest `storage/logs/security.jsonl` ke tabel `security_events`,
3. replay detector dari database,
4. menampilkan alert report,
5. menyimpan report JSON/HTML.

Format umum:

```powershell
python tools\attack-lab\attack_lab.py <scenario> --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay --detection-mode advanced --count 60
```

Report hasil tersimpan di:

```text
tools/attack-lab/reports
```

## 3. Test Brute Force Login

Tujuan:

- Menguji banyak login gagal ke `/login`.
- Detector harus mengenali pola credential attack.

Command Attack Lab:

```powershell
python tools\attack-lab\attack_lab.py bruteforce --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay --detection-mode advanced --count 40 --ip 203.0.113.50
```

Alternatif Laravel command:

```powershell
php artisan sim:bruteforce --base-url=http://127.0.0.1:8000 --attempts=40 --ip=203.0.113.50
python scripts\ingest_security_events.py --from-start
python scripts\replay_detector_from_db.py --detection-mode advanced --response-mode recommend
php artisan security:alerts-report --minutes=60
```

Expected alert:

```text
BRUTE_FORCE_IP
CREDENTIAL_STUFFING
ML_BRUTEFORCE
ANOMALY_BEHAVIOR
```

Yang dicek di dashboard:

- `/soc` bagian recent alerts
- `/soc` incident list
- alert dengan IP `203.0.113.50`

## 4. Test Directory / Path Scanning

Tujuan:

- Menguji banyak request ke path sensitif dan path tidak ada.
- Contoh target: `/.env`, `/wp-admin`, `/phpMyAdmin`, `/vendor`, `/.git/config`.

Command Attack Lab:

```powershell
python tools\attack-lab\attack_lab.py scan --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay --detection-mode advanced --count 50 --ip 198.51.100.77
```

Alternatif Laravel command:

```powershell
php artisan sim:scan --base-url=http://127.0.0.1:8000 --count=50 --ip=198.51.100.77 --include-sensitive=1
python scripts\ingest_security_events.py --from-start
python scripts\replay_detector_from_db.py --detection-mode advanced --response-mode recommend
php artisan security:alerts-report --minutes=60
```

Expected alert:

```text
SCAN_BURST
LOW_AND_SLOW_SCAN
ML_SCAN
ANOMALY_BEHAVIOR
```

Yang dicek:

- alert dengan IP `198.51.100.77`
- evidence path berisi request 404/sensitive path
- report HTML di `tools/attack-lab/reports`

## 5. Test SQL Injection dan XSS Payload

Tujuan:

- Menguji payload berbahaya pada endpoint `/search?q=...`.
- Payload dikirim sebagai query HTTP nyata.

Command Attack Lab:

```powershell
python tools\attack-lab\attack_lab.py injection --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay --detection-mode advanced --count 30 --ip 192.0.2.55
```

Alternatif Laravel command:

```powershell
php artisan sim:injection --base-url=http://127.0.0.1:8000 --ip=192.0.2.55 --repeats=5
python scripts\ingest_security_events.py --from-start
python scripts\replay_detector_from_db.py --detection-mode advanced --response-mode recommend
php artisan security:alerts-report --minutes=60
```

Tes manual via browser:

```text
http://127.0.0.1:8000/search?q=%27%20OR%201%3D1--
http://127.0.0.1:8000/search?q=%3Cscript%3Ealert(1)%3C/script%3E
http://127.0.0.1:8000/search?q=1%20UNION%20SELECT%20email,password%20FROM%20users
```

Setelah tes manual browser, proses log:

```powershell
python scripts\ingest_security_events.py --from-start
python scripts\replay_detector_from_db.py --detection-mode advanced --response-mode recommend
php artisan security:alerts-report --minutes=60
```

Expected alert:

```text
INJECTION_INDICATOR
ML_INJECTION
ANOMALY_BEHAVIOR
```

Catatan:

- Jika muncul dua alert sekaligus, misalnya `INJECTION_INDICATOR` dan `ML_INJECTION`, itu benar.
- `INJECTION_INDICATOR` berasal dari rule eksplisit.
- `ML_INJECTION` berasal dari klasifikasi model.

## 6. Test Privilege Probing / Unauthorized Admin Access

Tujuan:

- Menguji percobaan akses `/admin` tanpa hak admin.
- Ini bukan exploit privilege escalation, tetapi probing akses ilegal ke resource admin.

Command Attack Lab:

```powershell
python tools\attack-lab\attack_lab.py privilege --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay --detection-mode advanced --count 20 --ip 203.0.113.60
```

Tes manual:

1. Logout dari aplikasi.
2. Buka:

```text
http://127.0.0.1:8000/admin
```

3. Login sebagai user non-admin:

```text
user@example.com
password
```

4. Buka lagi:

```text
http://127.0.0.1:8000/admin
```

5. Proses log:

```powershell
python scripts\ingest_security_events.py --from-start
python scripts\replay_detector_from_db.py --detection-mode advanced --response-mode recommend
php artisan security:alerts-report --minutes=60
```

Expected alert:

```text
PRIVILEGE_PROBING
ANOMALY_BEHAVIOR
```

Yang dicek:

- status request 403 atau authorization denied
- alert terkait `/admin`

## 7. Test Anomaly Behavior

Tujuan:

- Menguji banyak request yang tampak normal tetapi volumenya tidak wajar.
- Berguna untuk melihat deteksi perilaku/anomali, bukan hanya signature payload.

Command:

```powershell
python tools\attack-lab\attack_lab.py anomaly --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay --detection-mode advanced --count 80 --ip 203.0.113.70
```

Expected alert:

```text
ANOMALY_BEHAVIOR
ML_SCAN
```

Catatan:

- Hasil anomaly bisa bergantung pada profile/model dan volume request.
- Jika belum muncul, naikkan `--count` menjadi `120`.

## 8. Test Mixed Attack Scenario

Tujuan:

- Menguji gabungan brute force, scan, injection, privilege probing, dan anomaly dalam satu run.

Command:

```powershell
python tools\attack-lab\attack_lab.py full --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay --detection-mode advanced --count 60 --ip 203.0.113.50
```

Expected alert:

```text
BRUTE_FORCE_IP
CREDENTIAL_STUFFING
SCAN_BURST
LOW_AND_SLOW_SCAN
INJECTION_INDICATOR
ML_BRUTEFORCE
ML_SCAN
ML_INJECTION
ANOMALY_BEHAVIOR
EXPLOIT_CHAIN_SUSPECTED
```

Jika ingin scenario Laravel bawaan:

```powershell
php artisan sim:scenario --base-url=http://127.0.0.1:8000 --rounds=1 --profile=fast
python scripts\ingest_security_events.py --from-start
python scripts\replay_detector_from_db.py --detection-mode advanced --response-mode recommend
php artisan security:alerts-report --minutes=60
```

## 9. Test Campaign + Coverage Matrix

Tujuan:

- Membuktikan bukan hanya alert muncul, tetapi juga coverage pass/fail.
- Cocok untuk presentasi atau validasi penguji.

Command:

```powershell
python tools\attack-lab\attack_lab.py --campaign campaigns/web-detector-validation.json --detector-root D:\project\Detector --detector-mode replay --detection-mode advanced
```

Coverage manual:

```powershell
python scripts\detector_coverage_matrix.py --expectations tools\attack-lab\coverage\web-basic-coverage.json
```

Expected:

```text
credential-attack: PASS
reconnaissance-scan: PASS
payload-injection: PASS
advanced-correlation: PASS
```

## 10. Test via Attack Lab UI

Jalankan UI:

```powershell
python tools\attack-lab\attack_lab_ui.py
```

Buka:

```text
http://127.0.0.1:8765
```

Isi:

```text
Base URL      : http://127.0.0.1:8000
Detector Root : D:\project\Detector
Detector Mode : replay
Detection Mode: advanced
```

Pilih scenario:

- `bruteforce`
- `scan`
- `injection`
- `privilege`
- `anomaly`
- `full`

Keunggulan UI:

- penguji bisa melihat bahwa request benar-benar dikirim,
- ada jumlah request,
- ada status HTTP,
- ada report HTML/JSON,
- lebih meyakinkan daripada CLI saja.

## 11. Test dengan IP Komputer Sendiri

Secara default, tool memakai header `X-Forwarded-For` agar IP skenario bisa berbeda-beda. Jika ingin aplikasi melihat IP asli koneksi:

```powershell
python tools\attack-lab\attack_lab.py scan --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay --real-source-ip
```

Jika target tetap `127.0.0.1`, IP yang terlihat biasanya tetap:

```text
127.0.0.1
```

Jika ingin memakai IP LAN komputer:

1. Jalankan Laravel pada semua interface:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

2. Cari IP LAN komputer:

```powershell
ipconfig
```

3. Jalankan Attack Lab ke IP LAN:

```powershell
python tools\attack-lab\attack_lab.py scan --base-url http://YOUR_LAN_IP:8000 --detector-root D:\project\Detector --detector-mode replay --real-source-ip --allow-non-local
```

Gunakan mode ini hanya di jaringan/lab yang Anda kuasai.

## 12. Cara Melihat Hasil

CLI:

```powershell
php artisan security:alerts-report --minutes=60
```

Pipeline health:

```powershell
php artisan security:pipeline-health
```

Dashboard:

```text
http://127.0.0.1:8000/soc
```

Alert page lama:

```text
http://127.0.0.1:8000/security/alerts
```

Attack Lab reports:

```text
tools/attack-lab/reports
```

Database cepat:

```powershell
php artisan tinker
```

```php
DB::table('security_events')->count();
DB::table('security_alerts')->count();
DB::table('security_alerts')->orderByDesc('detected_at')->first();
```

## 13. Mapping Serangan ke Alert

| Serangan | Endpoint / Pola | Alert yang Diharapkan |
| --- | --- | --- |
| Brute force | banyak POST `/login` gagal | `BRUTE_FORCE_IP`, `CREDENTIAL_STUFFING`, `ML_BRUTEFORCE` |
| Directory scan | banyak GET path 404/sensitif | `SCAN_BURST`, `LOW_AND_SLOW_SCAN`, `ML_SCAN` |
| SQL injection | `/search?q=' OR 1=1--` | `INJECTION_INDICATOR`, `ML_INJECTION` |
| XSS | `/search?q=<script>...` | `INJECTION_INDICATOR`, `ML_INJECTION` |
| Privilege probing | akses `/admin` tanpa hak | `PRIVILEGE_PROBING` |
| Anomaly behavior | request normal volume tinggi | `ANOMALY_BEHAVIOR` |
| Mixed chain | gabungan beberapa skenario | `EXPLOIT_CHAIN_SUSPECTED`, alert lain sesuai chain |

## 14. Troubleshooting

Jika app tidak reachable:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Jika CSS tidak tampil:

```powershell
npm run build
```

Jika report kosong:

```powershell
python scripts\ingest_security_events.py --from-start
python scripts\replay_detector_from_db.py --detection-mode advanced --response-mode recommend
php artisan security:alerts-report --minutes=60
```

Jika `security_events` kosong:

```powershell
Get-Content storage\logs\security.jsonl -Tail 5
php artisan security:ingest --from-start
```

Jika alert masih kosong:

```powershell
python scripts\replay_detector_from_db.py --detection-mode advanced --response-mode recommend
php artisan security:alerts-report --minutes=120
```

Jika terlalu banyak alert lama bercampur:

```powershell
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSocSeeder
```

Lalu ulangi skenario serangan.

## 15. Rekomendasi Urutan Demo ke Penguji

1. Tunjukkan app normal:

```text
http://127.0.0.1:8000
```

2. Jalankan brute force:

```powershell
python tools\attack-lab\attack_lab.py bruteforce --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay --detection-mode advanced --count 40
```

3. Tunjukkan alert report:

```powershell
php artisan security:alerts-report --minutes=60
```

4. Jalankan injection:

```powershell
python tools\attack-lab\attack_lab.py injection --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode replay --detection-mode advanced --count 30
```

5. Buka SOC dashboard:

```text
http://127.0.0.1:8000/soc
```

6. Tunjukkan report Attack Lab:

```text
tools/attack-lab/reports
```

7. Jalankan campaign:

```powershell
python tools\attack-lab\attack_lab.py --campaign campaigns/web-detector-validation.json --detector-root D:\project\Detector --detector-mode replay --detection-mode advanced
```
