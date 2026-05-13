# Runbook: Uji Serangan Manual Tanpa Tool

Dokumen ini berisi cara menguji deteksi serangan secara manual tanpa Attack Lab dan tanpa generator otomatis. Pengujian dilakukan dengan membuka URL di browser atau menjalankan request PowerShell sederhana.

Target lokal:

```text
http://127.0.0.1:8000
```

Gunakan hanya pada aplikasi lokal/lab yang Anda miliki atau diizinkan untuk diuji.

Catatan scope:

- Dokumen ini fokus pada serangan web aplikasi Laravel lokal seperti SQL injection, XSS, scan, brute force, credential stuffing, dan privilege probing.
- Untuk validasi XDR lanjutan seperti identity attack, cloud telemetry, multi-stage chain, endpoint telemetry replay, distributed pipeline, event sourcing, Redpanda/ClickHouse/OpenSearch/Qdrant, gunakan:

```text
README_XDR_VALIDATION_PLAYBOOK.md
```

## 1. Persiapan

Terminal 1:

```powershell
docker compose up -d
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSocSeeder
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Buka aplikasi:

```text
http://127.0.0.1:8000
```

Login SOC:

```text
http://127.0.0.1:8000/login
soc-admin@example.com / password
```

Dashboard:

```text
http://127.0.0.1:8000/soc
```

## 2. Cara Memproses Hasil Setelah Uji Manual

Setelah menjalankan request manual, proses log agar alert muncul:

```powershell
python scripts\ingest_security_events.py --from-start
python scripts\replay_detector_from_db.py --detection-mode advanced --response-mode recommend
php artisan security:alerts-report --minutes=60
```

Jika Anda sedang menjalankan mode realtime, cukup jalankan:

```powershell
php artisan security:alerts-report --minutes=15
```

Mode realtime berarti terminal berikut sudah aktif:

```powershell
python scripts\stream_producer_kafka.py --topic security_events
python scripts\realtime_detector_kafka_consumer.py --topic security_events --group-id realtime-demo --detection-mode advanced --response-mode recommend --use-active-deployment=0 --require-lock=0
```

## 3. SQL Injection Manual

Tujuan:

- Menguji payload SQL injection pada endpoint pencarian.

Buka satu per satu di browser:

```text
http://127.0.0.1:8000/search?q=%27%20OR%201%3D1--
```

```text
http://127.0.0.1:8000/search?q=1%20UNION%20SELECT%20email,password%20FROM%20users
```

```text
http://127.0.0.1:8000/search?q=admin%27%20AND%20SLEEP(1)--
```

```text
http://127.0.0.1:8000/search?q=%27%20OR%20%27a%27%3D%27a
```

Expected alert:

```text
INJECTION_INDICATOR
ML_INJECTION
ANOMALY_BEHAVIOR
```

Catatan:

- Satu request biasanya cukup untuk rule `INJECTION_INDICATOR`.
- Jika `ML_INJECTION` ikut muncul, itu benar karena model juga mengklasifikasikan payload sebagai injection.

## 4. XSS Manual

Tujuan:

- Menguji script payload pada parameter pencarian.

Buka di browser:

```text
http://127.0.0.1:8000/search?q=%3Cscript%3Ealert(1)%3C/script%3E
```

```text
http://127.0.0.1:8000/search?q=%3Cimg%20src=x%20onerror=alert(1)%3E
```

```text
http://127.0.0.1:8000/search?q=javascript%3Aalert(1)
```

```text
http://127.0.0.1:8000/search?q=%3Csvg%20onload=alert(1)%3E
```

Expected alert:

```text
INJECTION_INDICATOR
ML_INJECTION
ANOMALY_BEHAVIOR
```

Catatan:

- Payload tidak harus benar-benar mengeksekusi script di browser.
- Yang diuji adalah kemampuan sistem mendeteksi indikator payload berbahaya dari request.

## 5. Directory / Path Scanning Manual

Tujuan:

- Menguji scanning path sensitif dan banyak 404.

Buka URL berikut satu per satu:

```text
http://127.0.0.1:8000/.env
```

```text
http://127.0.0.1:8000/.git/config
```

```text
http://127.0.0.1:8000/wp-admin
```

```text
http://127.0.0.1:8000/phpMyAdmin
```

```text
http://127.0.0.1:8000/vendor
```

```text
http://127.0.0.1:8000/server-status
```

```text
http://127.0.0.1:8000/backup.zip
```

```text
http://127.0.0.1:8000/admin.php
```

```text
http://127.0.0.1:8000/config.php
```

Untuk memicu threshold scan, buka juga beberapa path acak:

```text
http://127.0.0.1:8000/scan/path-001
http://127.0.0.1:8000/scan/path-002
http://127.0.0.1:8000/scan/path-003
http://127.0.0.1:8000/scan/path-004
http://127.0.0.1:8000/scan/path-005
http://127.0.0.1:8000/scan/path-006
http://127.0.0.1:8000/scan/path-007
http://127.0.0.1:8000/scan/path-008
http://127.0.0.1:8000/scan/path-009
http://127.0.0.1:8000/scan/path-010
http://127.0.0.1:8000/scan/path-011
http://127.0.0.1:8000/scan/path-012
http://127.0.0.1:8000/scan/path-013
http://127.0.0.1:8000/scan/path-014
http://127.0.0.1:8000/scan/path-015
http://127.0.0.1:8000/scan/path-016
http://127.0.0.1:8000/scan/path-017
http://127.0.0.1:8000/scan/path-018
http://127.0.0.1:8000/scan/path-019
http://127.0.0.1:8000/scan/path-020
```

Expected alert:

```text
SCAN_BURST
LOW_AND_SLOW_SCAN
ML_SCAN
ANOMALY_BEHAVIOR
```

Catatan:

- Scan biasanya butuh banyak request karena rule memakai threshold jumlah path/404.
- Jika hanya membuka 1-2 URL, alert scan mungkin belum muncul.

## 6. Directory Scanning Manual via PowerShell

Jika ingin tetap manual tetapi tidak membuka 20 tab browser:

```powershell
$paths = @(
  "/.env",
  "/.git/config",
  "/wp-admin",
  "/phpMyAdmin",
  "/vendor",
  "/server-status",
  "/backup.zip",
  "/admin.php",
  "/config.php"
)
1..20 | ForEach-Object { $paths += "/scan/manual-$_" }
$paths | ForEach-Object {
  try {
    Invoke-WebRequest "http://127.0.0.1:8000$_" -UseBasicParsing | Out-Null
  } catch {}
}
```

Expected alert:

```text
SCAN_BURST
ML_SCAN
```

## 7. Brute Force Login Manual

Brute force sulit dilakukan hanya dengan membuka URL, karena login Laravel memakai CSRF token. Cara manual yang praktis adalah PowerShell mengambil token dari `/login`, lalu mengirim beberapa POST login gagal.

Jalankan:

```powershell
$base = "http://127.0.0.1:8000"
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$login = Invoke-WebRequest "$base/login" -WebSession $session -UseBasicParsing
$token = [regex]::Match($login.Content, 'name="_token"\s+value="([^"]+)"').Groups[1].Value
1..40 | ForEach-Object {
  try {
    Invoke-WebRequest "$base/login" `
      -Method POST `
      -WebSession $session `
      -ContentType "application/x-www-form-urlencoded" `
      -Body "_token=$token&email=attacker$_@example.com&password=wrong-password" `
      -UseBasicParsing | Out-Null
  } catch {}
}
```

Expected alert:

```text
BRUTE_FORCE_IP
CREDENTIAL_STUFFING
ML_BRUTEFORCE
ANOMALY_BEHAVIOR
```

Catatan:

- Semua request berasal dari IP lokal `127.0.0.1` jika tidak memakai header tambahan.
- Untuk memakai IP simulasi:

```powershell
$base = "http://127.0.0.1:8000"
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$login = Invoke-WebRequest "$base/login" -WebSession $session -UseBasicParsing
$token = [regex]::Match($login.Content, 'name="_token"\s+value="([^"]+)"').Groups[1].Value
1..40 | ForEach-Object {
  try {
    Invoke-WebRequest "$base/login" `
      -Method POST `
      -WebSession $session `
      -Headers @{"X-Forwarded-For"="203.0.113.50"} `
      -ContentType "application/x-www-form-urlencoded" `
      -Body "_token=$token&email=attacker$_@example.com&password=wrong-password" `
      -UseBasicParsing | Out-Null
  } catch {}
}
```

## 8. Credential Stuffing Manual

Credential stuffing mirip brute force, tetapi email yang dicoba banyak dan berbeda.

```powershell
$base = "http://127.0.0.1:8000"
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$login = Invoke-WebRequest "$base/login" -WebSession $session -UseBasicParsing
$token = [regex]::Match($login.Content, 'name="_token"\s+value="([^"]+)"').Groups[1].Value
1..30 | ForEach-Object {
  $email = "victim$_@example.com"
  try {
    Invoke-WebRequest "$base/login" `
      -Method POST `
      -WebSession $session `
      -Headers @{"X-Forwarded-For"="203.0.113.51"} `
      -ContentType "application/x-www-form-urlencoded" `
      -Body "_token=$token&email=$email&password=Password123!" `
      -UseBasicParsing | Out-Null
  } catch {}
}
```

Expected alert:

```text
CREDENTIAL_STUFFING
BRUTE_FORCE_IP
ML_BRUTEFORCE
```

## 9. Privilege Probing Manual

Tujuan:

- Menguji percobaan akses halaman admin tanpa izin.

Cara 1, tanpa login:

```text
http://127.0.0.1:8000/admin
```

Cara 2, login sebagai user biasa:

```text
http://127.0.0.1:8000/login
user@example.com / password
```

Lalu buka:

```text
http://127.0.0.1:8000/admin
```

Ulangi beberapa kali:

```text
http://127.0.0.1:8000/admin
http://127.0.0.1:8000/admin
http://127.0.0.1:8000/admin
```

Expected alert:

```text
PRIVILEGE_PROBING
ANOMALY_BEHAVIOR
```

Catatan:

- Jika hanya redirect ke login dan tidak menghasilkan 403, alert privilege bisa tidak muncul langsung.
- Lebih kuat jika login sebagai `user@example.com`, lalu akses `/admin`.

## 10. Sensitive File Probe Manual

Tujuan:

- Menguji probing file konfigurasi atau backup.

Buka:

```text
http://127.0.0.1:8000/.env
http://127.0.0.1:8000/.env.bak
http://127.0.0.1:8000/config.php
http://127.0.0.1:8000/database.sql
http://127.0.0.1:8000/backup.sql
http://127.0.0.1:8000/backup.zip
http://127.0.0.1:8000/storage/logs/laravel.log
```

Expected alert:

```text
SCAN_BURST
ML_SCAN
ANOMALY_BEHAVIOR
```

Catatan:

- Ini terdeteksi sebagai scanning/probing, bukan sebagai file disclosure sungguhan.

## 11. API Enumeration Manual

Tujuan:

- Menguji enumerasi endpoint API.

Buka:

```text
http://127.0.0.1:8000/api/items
http://127.0.0.1:8000/api/items/1
http://127.0.0.1:8000/api/items/999999
http://127.0.0.1:8000/api/users
http://127.0.0.1:8000/api/admin
http://127.0.0.1:8000/api/config
http://127.0.0.1:8000/api/debug
http://127.0.0.1:8000/api/.env
```

Expected alert:

```text
SCAN_BURST
ML_SCAN
ANOMALY_BEHAVIOR
```

## 12. Normal Traffic Baseline Manual

Tujuan:

- Membandingkan traffic normal agar terlihat bedanya dengan serangan.

Buka beberapa kali:

```text
http://127.0.0.1:8000/
http://127.0.0.1:8000/login
http://127.0.0.1:8000/search?q=library
http://127.0.0.1:8000/search?q=book
http://127.0.0.1:8000/search?q=journal
```

Expected:

```text
Tidak ada alert high severity.
Tidak ada INJECTION_INDICATOR.
Tidak ada BRUTE_FORCE_IP.
```

Jika alert tetap muncul, kemungkinan alert lama masih berada di window laporan. Gunakan `--minutes` lebih kecil atau reset database.

## 13. Melihat Hasil

Setelah uji manual, jalankan:

```powershell
python scripts\ingest_security_events.py --from-start
python scripts\replay_detector_from_db.py --detection-mode advanced --response-mode recommend
php artisan security:alerts-report --minutes=60
```

Buka dashboard:

```text
http://127.0.0.1:8000/soc
```

Cek security alert page:

```text
http://127.0.0.1:8000/security/alerts
```

Cek log mentah:

```powershell
Get-Content storage\logs\security.jsonl -Tail 20
```

Cek database:

```powershell
php artisan tinker
```

```php
DB::table('security_events')->count();
DB::table('security_alerts')->orderByDesc('detected_at')->first();
```

## 14. Mapping Manual Test ke Alert

| Uji Manual | Contoh | Alert yang Diharapkan |
| --- | --- | --- |
| SQL injection | `/search?q=' OR 1=1--` | `INJECTION_INDICATOR`, `ML_INJECTION` |
| XSS | `/search?q=<script>alert(1)</script>` | `INJECTION_INDICATOR`, `ML_INJECTION` |
| Path scanning | `/.env`, `/.git/config`, `/wp-admin` berulang | `SCAN_BURST`, `ML_SCAN` |
| Brute force | 40 POST `/login` gagal | `BRUTE_FORCE_IP`, `ML_BRUTEFORCE` |
| Credential stuffing | banyak email berbeda login gagal | `CREDENTIAL_STUFFING` |
| Privilege probing | user biasa akses `/admin` | `PRIVILEGE_PROBING` |
| API enumeration | banyak `/api/...` tidak valid | `SCAN_BURST`, `ML_SCAN` |
| Normal traffic | `/`, `/login`, `/search?q=book` | tidak ada alert high |

## 15. Troubleshooting

Jika alert kosong:

```powershell
Get-Content storage\logs\security.jsonl -Tail 10
python scripts\ingest_security_events.py --from-start
python scripts\replay_detector_from_db.py --detection-mode advanced --response-mode recommend
php artisan security:alerts-report --minutes=120
```

Jika log tidak bertambah:

- Pastikan `php artisan serve` masih berjalan.
- Pastikan membuka URL dari `http://127.0.0.1:8000`.
- Pastikan `.env` berisi:

```text
SECURITY_DETECTOR_ENABLED=true
```

Jika scan tidak terdeteksi:

- Tambah jumlah path yang dibuka.
- Scan membutuhkan banyak 404/path unik.

Jika brute force tidak terdeteksi:

- Pastikan jumlah POST gagal minimal 30-40.
- Pastikan CSRF token berhasil diambil.

Jika dashboard kosong tapi CLI ada alert:

- Login sebagai `soc-admin@example.com`.
- Buka `/soc`.
- Refresh browser.
- Gunakan `php artisan security:alerts-report --minutes=60` untuk bukti CLI.
