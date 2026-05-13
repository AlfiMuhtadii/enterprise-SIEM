# XDR AI/RAG Service

Standalone FastAPI service boundary for AI-assisted SOC workflows.

Responsibilities:
- Defensive incident analysis API.
- Retrieval API for SOC knowledge context.
- Embedding API for vector ingestion pipelines.
- Operational `/health` and `/metrics`.

Run:

```powershell
cd services\ai-rag-service
python -m venv .venv
.\.venv\Scripts\pip install -r requirements.txt
.\.venv\Scripts\python -m uvicorn main:app --host 0.0.0.0 --port 8094
```
