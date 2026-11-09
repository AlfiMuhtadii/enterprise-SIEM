# Production Ollama TLS

The local `ai-embeddings` profile publishes Ollama on loopback HTTP for
development. The production overlay removes that host port and places an
internal-only Nginx TLS endpoint at `https://ollama-tls:11434`. Plaintext is
limited to the Docker network hop from the proxy to the Ollama container.

The endpoint uses the repository internal CA and requires TLS 1.2 or newer.
Laravel generation and embedding requests verify both the CA chain and the
`ollama-tls` hostname. This is server-authenticated TLS, not mutual TLS; Ollama
does not receive client certificates or autonomous execution privileges.

## Start

Generate production secrets, then start the application and optional AI profile:

```powershell
docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml --profile app --profile ai-embeddings up -d internal-mtls-certs-init ollama ollama-tls app
docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml exec ollama ollama pull all-minilm
```

Pull the configured generation model separately before setting
`SOC_AI_PROVIDER=ollama`. Set `SOC_EMBEDDING_MODEL_URL=https://ollama-tls:11434`
only after `all-minilm` is available; otherwise the existing hashed-keyword
fallback remains active.

## Validate

```powershell
python scripts/xdr_ollama_tls_compose_validate.py
docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml --profile app --profile ai-embeddings config --quiet
```

Do not publish ports `11434` from either service in production. Do not use
`SOC_OLLAMA_VERIFY_TLS=false` or `--insecure` in health checks.
