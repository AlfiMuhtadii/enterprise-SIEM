# Runbook: Dari Awal Sampai Data Muncul

Dokumen ini menjelaskan urutan menjalankan platform dari nol sampai alert dan incident terlihat di SOC dashboard.

## 1. Prasyarat

Pastikan sudah tersedia:

- Docker Desktop aktif
- PHP dependency sudah ada di `vendor/`
- Node dependency sudah ada di `node_modules/`
- File `.env` sudah ada

Jika `.env` belum ada:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

## 2. Jalankan Infrastruktur Dasar

Jalankan semua infrastruktur dari satu compose utama:

```powershell
docker compose up -d
```

Service yang naik:

- Redpanda: `http://127.0.0.1:8082`
- Redpanda Console: `http://127.0.0.1:8080`
- PostgreSQL: `127.0.0.1:5432`
- ClickHouse: `http://127.0.0.1:8123`
- Grafana: `http://127.0.0.1:3000`
- OpenSearch: `http://127.0.0.1:9200`
- Qdrant: `http://127.0.0.1:6333`

Cek container:

```powershell
docker compose ps
```

## 3. Migrasi dan Seed Database

Untuk reset bersih:

```powershell
php artisan migrate:fresh --seed
```

Tambahkan data demo SOC:

```powershell
php artisan db:seed --class=DemoSocSeeder
```

User demo:

```text
SOC Admin   : soc-admin@example.com / password
SOC Analyst : soc-analyst@example.com / password
SOC Viewer  : soc-viewer@example.com / password
App Admin   : admin@example.com / password
App User    : user@example.com / password
```

## 4. Build Frontend Laravel

Jika CSS/JS belum tampil:

```powershell
npm run build
```

Untuk mode development:

```powershell
npm run dev
```

Gunakan salah satu. Untuk demo cepat, `npm run build` cukup.

## 5. Jalankan Laravel App

Mode lokal paling mudah:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Buka:

```text
http://127.0.0.1:8000/login
```

Login sebagai:

```text
soc-admin@example.com
password
```

Dashboard SOC:

```text
http://127.0.0.1:8000/soc
```

## 6. Setup Topic dan Storage XDR

Jalankan setup infra XDR:

```powershell
python scripts\xdr_setup_infra.py --output reports\xdr_infra_setup_event_driven.json
```

Validasi storage:

```powershell
php artisan xdr:storage-validate
```

Expected:

```text
raw_telemetry: clickhouse healthy
incidents_workflow_rbac: postgresql healthy
searchable_telemetry: opensearch healthy
rag_vectors: qdrant healthy
```

## 7. Jalankan Service Event-Driven XDR

Jalankan service XDR/strangler:

```powershell
docker compose --profile strangler up -d --build
```

Atau jika hanya ingin alert dan incident flow:

```powershell
docker compose --profile strangler up -d --build alert-writer-service incident-builder-service ai-rag-service
```

Cek status:

```powershell
php artisan xdr:strangler-status
```

Cek health service:

```powershell
Invoke-RestMethod http://127.0.0.1:8095/health
Invoke-RestMethod http://127.0.0.1:8096/health
```

Expected:

```text
alert-writer consumes xdr.alerts and produces alerts.created
incident-builder consumes alerts.created and produces incidents.updated
```

## 8. Kirim Event Demo ke Pipeline

Flow target:

```text
xdr.alerts
  -> alert-writer-service
  -> alerts.created
  -> incident-builder-service
  -> incidents.updated
```

Kirim satu alert demo:

```powershell
python scripts\xdr_send_demo_alert.py
```

Cek metrics:

```powershell
Invoke-RestMethod http://127.0.0.1:8095/metrics
Invoke-RestMethod http://127.0.0.1:8096/metrics
```

Expected:

```text
alert-writer alerts_seen > 0
alert-writer alerts_written > 0
alert-writer events_published > 0
incident-builder incidents_built > 0
incident-builder incident_updates > 0
incident-builder events_published > 0
```

Cek DLQ:

```powershell
Invoke-RestMethod http://127.0.0.1:8095/dlq
Invoke-RestMethod http://127.0.0.1:8096/dlq
```

Expected:

```text
count: 0
```

## 9. Cek Data di Laravel

Buka:

```text
http://127.0.0.1:8000/soc
```

Data yang harus terlihat:

- Recent alerts bertambah
- Incident list bertambah
- Severity summary berubah
- Operational metrics service terlihat
- Separated Service Migration menunjukkan alert-writer dan incident-builder aktif

API cek cepat:

```powershell
Invoke-RestMethod http://127.0.0.1:8000/soc/api/alerts
Invoke-RestMethod http://127.0.0.1:8000/soc/api/incidents
Invoke-RestMethod http://127.0.0.1:8000/soc/api/metrics
```

Catatan: endpoint `/soc/api/*` butuh session login. Jika dipanggil langsung dari PowerShell tanpa cookie login, bisa redirect/unauthorized. Untuk pengecekan tanpa browser, pakai command database atau report.

## 10. Cek Data Lewat CLI

Alert report:

```powershell
php artisan security:alerts-report --minutes=60
```

Cek jumlah alert/incident di PostgreSQL:

```powershell
php artisan tinker
```

Lalu:

```php
DB::table('security_alerts')->count();
DB::table('security_incidents')->count();
DB::table('security_alerts')->orderByDesc('created_at')->first();
DB::table('security_incidents')->orderByDesc('created_at')->first();
```

Keluar dari tinker:

```php
exit
```

## 11. Cek Data di Redpanda Console

Buka:

```text
http://127.0.0.1:8080
```

Topic penting:

- `xdr.alerts`
- `alerts.created`
- `incidents.updated`
- `xdr.alerts.dlq`
- `alerts.created.dlq`
- `incidents.updated.dlq`

Jika pipeline berjalan benar, topic `xdr.alerts`, `alerts.created`, dan `incidents.updated` memiliki message.

## 12. Cek Grafana

Buka:

```text
http://127.0.0.1:3000
```

Login:

```text
admin / admin
```

Grafana dipakai untuk metrik/analytics. Jika dashboard kosong, penyebab umum:

- data belum masuk ClickHouse
- dashboard yang dibuka tidak membaca tabel PostgreSQL SOC
- pipeline alert/incident tetap bisa valid walaupun Grafana belum menampilkan panel tertentu

Untuk membuktikan SOC data, prioritaskan `/soc`, `security:alerts-report`, dan metrics service `8095/8096`.

## 13. Urutan Cepat untuk Demo

Terminal 1:

```powershell
docker compose up -d
python scripts\xdr_setup_infra.py --output reports\xdr_infra_setup_event_driven.json
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSocSeeder
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Terminal 2:

```powershell
docker compose --profile strangler up -d --build alert-writer-service incident-builder-service ai-rag-service
python scripts\xdr_send_demo_alert.py
php artisan security:alerts-report --minutes=60
```

Browser:

```text
http://127.0.0.1:8000/login
soc-admin@example.com / password
http://127.0.0.1:8000/soc
```

## 14. Troubleshooting

Jika Docker error:

```powershell
docker compose ps
docker compose logs redpanda --tail=80
docker compose logs alert-writer-service --tail=80
docker compose logs incident-builder-service --tail=80
```

Jika app tidak bisa dibuka:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Jika CSS tidak muncul:

```powershell
npm run build
```

Jika user kosong:

```powershell
php artisan db:seed --class=UserSeeder
php artisan db:seed --class=DemoSocSeeder
```

Jika alert tidak muncul:

```powershell
python scripts\xdr_send_demo_alert.py
Invoke-RestMethod http://127.0.0.1:8095/metrics
Invoke-RestMethod http://127.0.0.1:8096/metrics
Invoke-RestMethod http://127.0.0.1:8095/dlq
Invoke-RestMethod http://127.0.0.1:8096/dlq
```

Jika `incident_updates` tetap `0`, cek koneksi DB dari service:

```powershell
docker compose logs incident-builder-service --tail=80
```

Jika topic belum ada:

```powershell
python scripts\xdr_setup_infra.py --output reports\xdr_infra_setup_event_driven.json
docker exec detector-redpanda rpk topic list
```
