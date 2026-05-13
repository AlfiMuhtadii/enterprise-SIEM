# SOC LLM Providers, Guardrails, and RAG

This phase upgrades AI assistance with pluggable LLM providers, defensive guardrails, and knowledge retrieval.

## Provider Configuration

Default safe mode:

```env
SOC_AI_PROVIDER=local
SOC_AI_MODEL=local-heuristic
```

OpenAI-compatible endpoint:

```env
SOC_AI_PROVIDER=openai-compatible
SOC_AI_MODEL=gpt-compatible-model
SOC_OPENAI_COMPATIBLE_BASE_URL=https://your-provider.example/v1
SOC_OPENAI_COMPATIBLE_API_KEY=REPLACE
```

Ollama:

```env
SOC_AI_PROVIDER=ollama
SOC_OLLAMA_BASE_URL=http://127.0.0.1:11434
SOC_OLLAMA_MODEL=llama3.1
```

If a remote provider is unavailable or unconfigured, the system falls back to the local heuristic provider and records fallback metadata in `ai_execution_history`.

## Guardrails

AI outputs are constrained to defensive SOC analysis:

- evidence-only summarization
- no offensive instruction generation
- prompt/result traceability
- confidence labels
- hallucination warning markers
- analyst review required before trust/use

Unsafe prompt or output markers are written to `ai_guardrail_events`.

## RAG / Knowledge Retrieval

Knowledge base entries are indexed with a local keyword embedding in `soc_knowledge_embeddings`.

AI generation retrieves related content from:

- rule documentation
- IOC notes
- investigation templates
- lessons learned
- analyst notes
- MITRE reference notes

Retrieved entries are stored as `retrieval_citations` on AI suggestions.

## AI Operations Visibility

The SOC dashboard shows:

- provider usage
- model usage
- latency metrics
- confidence distribution
- rejected AI outputs
- retrieval citation usage
- guardrail events
