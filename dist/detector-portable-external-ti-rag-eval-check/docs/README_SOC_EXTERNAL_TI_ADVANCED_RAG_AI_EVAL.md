# External Threat Intel, Advanced RAG, and AI Evaluation

This phase adds operational integrations and evaluation loops for AI-assisted SOC work.

## External Threat Intelligence

Open `SOC Dashboard -> Threat intel`.

Supported lookup modes:

- VirusTotal-like lookup
- AbuseIPDB-like lookup
- generic webhook enrichment
- local fallback reputation

Supported feed imports:

- JSONL IOC feeds
- MISP-style JSON
- OpenCTI-style JSON

All lookups are stored in `external_threat_intel_lookups`. Feed runs are tracked in `external_ioc_feeds`.

Configuration:

```env
SOC_TI_VIRUSTOTAL_API_KEY=
SOC_TI_ABUSEIPDB_API_KEY=
SOC_TI_MISP_BASE_URL=
SOC_TI_MISP_API_KEY=
SOC_TI_OPENCTI_BASE_URL=
SOC_TI_OPENCTI_API_KEY=
SOC_TI_WEBHOOK_URL=
```

If external credentials are missing, lookups fall back to local IOC reputation and still record lookup history.

## Advanced RAG

Supported vector-store modes:

```env
SOC_RAG_VECTOR_STORE=local-keyword
SOC_RAG_EMBEDDING_PROVIDER=local-keyword
```

Optional Qdrant mode:

```env
SOC_RAG_VECTOR_STORE=qdrant
SOC_QDRANT_BASE_URL=http://127.0.0.1:6333
SOC_QDRANT_COLLECTION=soc_knowledge
```

If Qdrant is unavailable, retrieval falls back to local keyword vectors. Retrieval runs are stored in `rag_retrieval_runs` with citation quality scores.

## AI Evaluation

Run:

```powershell
php artisan soc:ai-evaluate --days=7
```

Metrics:

- summary accuracy estimate
- hallucination rate
- citation coverage
- analyst acceptance rate
- average AI latency

Results are stored in `ai_evaluation_runs` and surfaced on the SOC dashboard.
