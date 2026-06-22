# Runbook: Demo Realcase dan Realtime

Dokumen ini menjelaskan cara menjalankan demo yang lebih mendekati kondisi nyata:

- request benar-benar dikirim ke aplikasi Laravel,
- aplikasi mencatat event ke `storage/logs/security.jsonl`,
- producer men-stream log ke Redpanda topic `security_events`,
- detector consumer membaca topic secara realtime,
- alert dan response masuk ke database tanpa replay manual.

Gunakan hanya pada aplikasi lokal/lab yang Anda miliki atau diizinkan untuk diuji.

## 1. Arsitektur Demo Realtime

Alur realtime:

```text
Browser / Attack Lab / php artisan sim:*
  -> Laravel app
  -> SecurityRequestLogger middleware
  -> storage/logs/security.jsonl
  -> stream_producer_kafka.py
  -> Redpanda topic: security_events
  -> realtime_detector_kafka_consumer.py
  -> PostgreSQL: security_alerts, security_responses
  -> SOC dashboard / security:alerts-report
```

Perbedaan dengan mode replay:

```text
Replay mode    : request -> log -> ingest DB -> replay detector
Realtime mode  : request -> log -> stream topic -> detector consumer live
```

Untuk demo realcase, gunakan realtime mode.

## 2. Prasyarat

Pastikan Docker Desktop aktif.

Pastikan dependency Python Kafka tersedia:

```powershell
python -m pip install -r scripts\requirements-ingest.txt
```

Jika `.env` belum ada:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

## 3. Start Infrastruktur

Terminal 1:

```powershell
docker compose up -d
```

Cek:

```powershell
docker compose ps
```

Minimal container yang harus running:

- `detector-redpanda`
- `detector-redpanda-console`
- `detector-soc-postgres`
- `detector-clickhouse`
- `detector-grafana`
- `detector-opensearch`
- `detector-qdrant`

Setup topic dan storage XDR:

```powershell
python scripts\xdr_setup_infra.py --output reports\xdr_infra_setup_realtime.json
php artisan xdr:storage-validate
```

Buat topic web detector jika belum ada:

```powershell
docker exec detector-redpanda rpk topic create security_events -p 6 -r 1
```

Jika topic sudah ada dan command mengembalikan `TOPIC_ALREADY_EXISTS`, itu aman.

## 4. Reset dan Seed Data

Terminal 1:

```powershell
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSocSeeder
npm run build
```

User login SOC:

```text
soc-admin@example.com / password
soc-analyst@example.com / password
soc-viewer@example.com / password
```

## 5. Start Laravel App

Terminal 1:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Buka:

```text
http://127.0.0.1:8000/login
```

Login:

```text
soc-admin@example.com
password
```

Buka SOC dashboard:

```text
http://127.0.0.1:8000/soc
```

## 6. Start Producer Realtime

Terminal 2:

```powershell
python scripts\stream_producer_kafka.py --topic security_events
```

Output normal:

```text
Streaming file: D:\project\Detector\storage\logs\security.jsonl
BootstrapServers: 127.0.0.1:19092
Topic: security_events
```

Catatan:

- Tanpa `--from-start`, producer hanya mengirim event baru setelah producer berjalan.
- Ini mode paling bersih untuk demo realtime.

Jika ingin mengirim ulang semua log lama:

```powershell
python scripts\stream_producer_kafka.py --topic security_events --from-start
```

## 7. Start Detector Consumer Realtime

Terminal 3:

```powershell
python scripts\realtime_detector_kafka_consumer.py --topic security_events --group-id realtime-demo --detection-mode advanced --response-mode recommend --use-active-deployment=0 --require-lock=0
```

Output normal:

```text
BootstrapServers: 127.0.0.1:19092
ConsumerGroup: realtime-demo
Topic: security_events
DetectionMode: advanced
ResponseMode: recommend
Realtime Kafka detector started...
```

Catatan penting:

- Gunakan `--response-mode recommend` untuk demo aman. Sistem membuat rekomendasi response, tetapi tidak benar-benar memaksa aksi otomatis.
- Gunakan `--response-mode auto` hanya jika ingin response policy dijalankan otomatis.
- Jangan pisahkan argumen seperti `--use-active- deployment=0`; harus tepat `--use-active-deployment=0`.

## 8. Jalankan Service XDR Event-Driven Tambahan

Terminal 4:

```powershell
docker compose --profile strangler up -d --build alert-writer-service incident-builder-service ai-rag-service
```

Ini untuk jalur XDR event-driven:

```text
xdr.alerts -> alert-writer-service -> alerts.created -> incident-builder-service -> incidents.updated
```

Untuk web detector realtime dasar, yang utama tetap producer dan detector consumer pada Terminal 2 dan 3.

## 9. Realcase 1: Brute Force Login Realtime

Terminal 5:

```powershell
python tools\attack-lab\attack_lab.py bruteforce --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode none --detection-mode advanced --count 40 --ip 203.0.113.50
```

Kenapa `--detector-mode none`?

- Karena detector realtime sudah berjalan di Terminal 3.
- Attack Lab hanya perlu mengirim traffic HTTP nyata.
- Jangan pakai `replay` untuk demo realtime agar tidak bercampur dengan replay mode.

Expected alert:

```text
BRUTE_FORCE_IP
CREDENTIAL_STUFFING
ML_BRUTEFORCE
ANOMALY_BEHAVIOR
```

Cek:

```powershell
php artisan security:alerts-report --minutes=15
```

## 10. Realcase 2: Directory Scanning Realtime

Terminal 5:

```powershell
python tools\attack-lab\attack_lab.py scan --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode none --detection-mode advanced --count 60 --ip 198.51.100.77
```

Traffic yang dikirim:

- `/.env`
- `/wp-admin`
- `/phpMyAdmin`
- `/vendor`
- `/server-status`
- `/.git/config`
- path acak yang menghasilkan banyak 404

Expected alert:

```text
SCAN_BURST
LOW_AND_SLOW_SCAN
ML_SCAN
ANOMALY_BEHAVIOR
```

Cek:

```powershell
php artisan security:alerts-report --minutes=15
```

## 11. Realcase 3: SQL Injection dan XSS Realtime

Terminal 5:

```powershell
python tools\attack-lab\attack_lab.py injection --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode none --detection-mode advanced --count 30 --ip 192.0.2.55
```

Payload yang dikirim ke `/search?q=`:

```text
' OR 1=1--
<script>alert(1)</script>
1 UNION SELECT email,password FROM users
admin' AND SLEEP(1)--
javascript:alert(1)
```

Expected alert:

```text
INJECTION_INDICATOR
ML_INJECTION
ANOMALY_BEHAVIOR
```

Tes manual via browser:

```text
http://127.0.0.1:8000/search?q=%27%20OR%201%3D1--
http://127.0.0.1:8000/search?q=%3Cscript%3Ealert(1)%3C/script%3E
http://127.0.0.1:8000/search?q=1%20UNION%20SELECT%20email,password%20FROM%20users
```

Jika producer dan consumer realtime sedang berjalan, alert akan diproses otomatis.

## 12. Realcase 4: Privilege Probing Realtime

Terminal 5:

```powershell
python tools\attack-lab\attack_lab.py privilege --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode none --detection-mode advanced --count 25 --ip 203.0.113.60
```

Yang diuji:

- akses berulang ke `/admin`,
- user tidak punya hak admin,
- request menghasilkan redirect/403/authorization denied.

Expected alert:

```text
PRIVILEGE_PROBING
ANOMALY_BEHAVIOR
```

## 13. Realcase 5: Anomaly Behavior Realtime

Terminal 5:

```powershell
python tools\attack-lab\attack_lab.py anomaly --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode none --detection-mode advanced --count 100 --ip 203.0.113.70
```

Yang diuji:

- request tampak normal,
- volume tinggi dari satu sumber,
- tidak selalu mengandung payload eksplisit.

Expected alert:

```text
ANOMALY_BEHAVIOR
ML_SCAN
```

Jika belum muncul, naikkan:

```powershell
--count 150
```

## 14. Realcase 6: Mixed Attack Chain Realtime

Terminal 5:

```powershell
python tools\attack-lab\attack_lab.py full --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode none --detection-mode advanced --count 60 --ip 203.0.113.50
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

## 15. Melihat Hasil Realtime

Alert report:

```powershell
php artisan security:alerts-report --minutes=15
```

Pipeline health:

```powershell
php artisan security:pipeline-health --minutes=15
```

SOC dashboard:

```text
http://127.0.0.1:8000/soc
```

Security alert page:

```text
http://127.0.0.1:8000/security/alerts
```

Redpanda Console:

```text
http://127.0.0.1:8080
```

Topic yang dicek:

```text
security_events
```

Grafana:

```text
http://127.0.0.1:3000
admin / admin
```

Catatan:

- Untuk bukti realtime, buka Redpanda Console topic `security_events` saat serangan dijalankan.
- Di terminal producer, counter `sent=...` akan bertambah.
- Di terminal detector, alert akan diproses saat message masuk.

## 16. Membuktikan Realtime ke Penguji

Urutan demo yang paling jelas:

1. Buka SOC dashboard:

```text
http://127.0.0.1:8000/soc
```

2. Buka Redpanda Console:

```text
http://127.0.0.1:8080
```

3. Tampilkan Terminal 2 producer dan Terminal 3 detector.

4. Jalankan serangan:

```powershell
python tools\attack-lab\attack_lab.py injection --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode none --detection-mode advanced --count 30 --ip 192.0.2.55
```

5. Tampilkan alert report:

```powershell
php artisan security:alerts-report --minutes=15
```

6. Refresh `/soc`, tunjukkan alert/incident terbaru.

Poin yang dijelaskan:

- request masuk ke Laravel,
- log JSONL bertambah,
- producer mengirim ke Redpanda,
- consumer mendeteksi,
- database alert bertambah,
- dashboard membaca hasil dari database.

## 17. Mode IP Asli Komputer

Default Attack Lab mengirim header `X-Forwarded-For` supaya skenario IP bisa dikontrol. Jika ingin app melihat IP koneksi asli:

```powershell
python tools\attack-lab\attack_lab.py scan --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode none --real-source-ip --count 60
```

Jika target `127.0.0.1`, IP yang terlihat biasanya:

```text
127.0.0.1
```

Jika ingin memakai IP LAN komputer:

Terminal 1:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

Cari IP:

```powershell
ipconfig
```

Jalankan:

```powershell
python tools\attack-lab\attack_lab.py scan --base-url http://YOUR_LAN_IP:8000 --detector-root D:\project\Detector --detector-mode none --real-source-ip --allow-non-local --count 60
```

Gunakan hanya di jaringan/lab yang Anda kendalikan.

## 18. Jika Ingin Realcase dari Browser Manual

Pastikan producer dan consumer realtime masih jalan.

SQL injection:

```text
http://127.0.0.1:8000/search?q=%27%20OR%201%3D1--
```

XSS:

```text
http://127.0.0.1:8000/search?q=%3Cscript%3Ealert(1)%3C/script%3E
```

Directory scan manual:

```text
http://127.0.0.1:8000/.env
http://127.0.0.1:8000/wp-admin
http://127.0.0.1:8000/phpMyAdmin
http://127.0.0.1:8000/.git/config
```

Privilege probing:

```text
http://127.0.0.1:8000/admin
```

Setelah 10-30 detik:

```powershell
php artisan security:alerts-report --minutes=15
```

Catatan:

- Satu request manual injection biasanya cukup untuk `INJECTION_INDICATOR`.
- Brute force dan scan butuh banyak request, jadi lebih praktis memakai Attack Lab.

## 19. Troubleshooting Realtime

### App tidak reachable

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

### Producer tidak mengirim event

Cek file log:

```powershell
Get-Content storage\logs\security.jsonl -Tail 5
```

Jika log tidak bertambah, request belum masuk ke Laravel atau logging mati.

Cek `.env`:

```text
SECURITY_DETECTOR_ENABLED=true
```

### Consumer tidak jalan karena confluent-kafka hilang

```powershell
python -m pip install -r scripts\requirements-ingest.txt
```

### Topic tidak ada

```powershell
docker exec detector-redpanda rpk topic create security_events -p 6 -r 1
docker exec detector-redpanda rpk topic list
```

### Alert kosong tetapi event masuk Redpanda

Jalankan consumer dengan group id baru agar membaca dari awal:

```powershell
python scripts\realtime_detector_kafka_consumer.py --topic security_events --group-id realtime-demo-new --detection-mode advanced --response-mode recommend --use-active-deployment=0 --require-lock=0
```

Atau kirim event baru setelah consumer berjalan.

### Ingin fallback sementara ke replay

```powershell
python scripts\ingest_security_events.py --from-start
python scripts\replay_detector_from_db.py --detection-mode advanced --response-mode recommend
php artisan security:alerts-report --minutes=60
```

Jika replay menghasilkan alert tetapi realtime tidak, masalahnya ada pada producer/Redpanda/consumer group, bukan rule deteksi.

### Dashboard kosong

Cek CLI dulu:

```powershell
php artisan security:alerts-report --minutes=60
php artisan security:pipeline-health --minutes=60
```

Jika CLI ada alert tapi dashboard kosong:

- pastikan login sebagai `soc-admin@example.com`,
- buka `/soc`,
- refresh browser,
- pastikan filter waktu dashboard tidak terlalu sempit.

## 20. Ringkasan Terminal

Terminal 1, app:

```powershell
docker compose up -d
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSocSeeder
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Terminal 2, producer:

```powershell
python scripts\stream_producer_kafka.py --topic security_events
```

Terminal 3, realtime detector:

```powershell
python scripts\realtime_detector_kafka_consumer.py --topic security_events --group-id realtime-demo --detection-mode advanced --response-mode recommend --use-active-deployment=0 --require-lock=0
```

Terminal 4, attacker lab:

```powershell
python tools\attack-lab\attack_lab.py full --base-url http://127.0.0.1:8000 --detector-root D:\project\Detector --detector-mode none --detection-mode advanced --count 60
```

Terminal 5, hasil:

```powershell
php artisan security:alerts-report --minutes=15
php artisan security:pipeline-health --minutes=15
```
