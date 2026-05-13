# XDR Strangler Migration and Operational Validation

This phase keeps Laravel as the SOC control plane while gradually extracting high-throughput processing into specialized services.

Laravel remains responsible for:
- RBAC and authenticated SOC access.
- Incidents, workflow, analyst actions, reports, and dashboard.
- Operational visibility over extracted services.

Extracted service boundaries:
- `services/ingestion-gateway`: Go ingestion gateway for signed telemetry batches and `telemetry.raw` publishing.
- `services/normalizer-worker`: Go normalizer worker for schema validation and `telemetry.normalized` forwarding.
- `services/ai-rag-service`: FastAPI AI/RAG service for defensive incident analysis, retrieval, embeddings, and metrics.
- XDR correlation remains on the existing Laravel/Python bridge while the migration boundary is validated.

Run realistic large-scale validation:

```powershell
python scripts\xdr_operational_validate.py --generate --events 52500 --workers 6 --duration-minutes 240 --noise 0.35 --output reports\xdr_operational_validation.json
python scripts\xdr_strangler_e2e_validate.py --events 50000 --baseline-events 100 --batch-size 1000 --output reports\xdr_strangler_e2e_validation.json
```

Run Laravel operational visibility:

```powershell
php artisan xdr:strangler-status
php artisan xdr:stream-maturity --consumers=6 --partitions=12 --replay-events=52500
php artisan xdr:large-scale-validate --normal=50000 --malicious=2500 --duration-minutes=240 --noise=0.35
php artisan xdr:storage-maturity
php artisan xdr:recovery-validate --scenario=node-restart
```

Run extracted services locally:

```powershell
cd services\ingestion-gateway
go run . -addr :8091

cd ..\normalizer-worker
go run . -file ..\..\storage\logs\xdr_realistic_large.jsonl

cd ..\ai-rag-service
python -m uvicorn main:app --host 0.0.0.0 --port 8094
```

Run extracted services with Docker Compose profile:

```powershell
docker compose -f infra\distributed\docker-compose.xdr.yml --profile strangler up -d --build
```

If Redpanda is already running outside this Compose project, keep the default `host.docker.internal:8082` setting used by the strangler services.

Expected report fields:
- Throughput metrics.
- Latency percentile metrics.
- FP/FN estimates per telemetry domain.
- Replay stability metrics.
- Correlation degradation metrics.
- Storage pressure metrics.
- Consumer lag metrics.
- Distributed recovery metrics.
- Service extraction health.

Measured service endpoints:
- Go ingestion gateway: `http://127.0.0.1:8091/health`
- Go normalizer worker: `http://127.0.0.1:8092/health`
- FastAPI AI/RAG service: `http://127.0.0.1:8094/health`
