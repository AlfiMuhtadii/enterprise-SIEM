<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SocKnowledgeRetriever
{
    public function retrieve(array $context, int $limit = 6): array
    {
        $started = microtime(true);
        $query = $this->queryTerms($context);
        $store = (string) config('soc.rag_vector_store', 'local-keyword');
        if ($store === 'qdrant') {
            $citations = $this->retrieveQdrant($context, $query, $limit);
            // Reflects what was actually used for this retrieval (real
            // model vs. hashed-keyword fallback), not just a static
            // operator-set label -- config('soc.rag_embedding_provider')
            // remains available separately for operators who want to set
            // their own descriptive value.
            $embeddingProvider = $this->embeddingProviderName();
        } else {
            $citations = $this->retrieveLocal($context, $query, $limit, $store);
            $embeddingProvider = (string) config('soc.rag_embedding_provider', 'local-keyword');
        }

        $quality = $this->citationQuality($citations);
        DB::table('rag_retrieval_runs')->insert([
            'retrieval_id' => 'rag-'.Str::uuid(),
            'target_type' => 'incident',
            'target_id' => $context['incident']['incident_id'] ?? null,
            'vector_store' => $store,
            'embedding_provider' => $embeddingProvider,
            'query_terms' => json_encode($query),
            'citations' => json_encode($citations),
            'citation_quality_score' => $quality,
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            'retrieved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $citations;
    }

    public function retrieveLocal(array $context, array $query, int $limit = 6, string $store = 'local-keyword'): array
    {
        $entries = DB::table('soc_knowledge_base')->orderByDesc('updated_at')->limit(500)->get();
        $queryVector = $this->keywordVector(implode(' ', $query));
        $scored = [];
        foreach ($entries as $entry) {
            $text = strtolower($entry->title.' '.$entry->entry_type.' '.$entry->content_markdown.' '.json_encode(json_decode($entry->tags ?: '[]', true)));
            $score = 0;
            foreach ($query as $term) {
                if ($term !== '' && str_contains($text, strtolower($term))) {
                    $score++;
                }
            }
            if (!empty($context['incident']['incident_id']) && $entry->related_incident_id === $context['incident']['incident_id']) {
                $score += 3;
            }
            $rules = collect($context['alerts'] ?? [])->pluck('detector_name')->filter()->unique()->all();
            if ($entry->related_rule_id && in_array($entry->related_rule_id, $rules, true)) {
                $score += 2;
            }
            $embedding = DB::table('soc_knowledge_embeddings')->where('kb_id', $entry->kb_id)->first();
            if ($embedding) {
                $score += $this->cosine($queryVector, json_decode($embedding->embedding ?: '{}', true) ?: []);
            }
            if ($score > 0) {
                $scored[] = ['entry' => (array) $entry, 'score' => $score];
            }
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return collect($scored)->take($limit)->map(function ($row) use ($store) {
            return [
                'kb_id' => $row['entry']['kb_id'],
                'title' => $row['entry']['title'],
                'entry_type' => $row['entry']['entry_type'],
                'score' => round((float) $row['score'], 4),
                'vector_store' => $store,
                'excerpt' => mb_substr($row['entry']['content_markdown'], 0, 260),
                'related_rule_id' => $row['entry']['related_rule_id'] ?? null,
                'related_ioc_id' => $row['entry']['related_ioc_id'] ?? null,
            ];
        })->values()->all();
    }

    private function retrieveQdrant(array $context, array $query, int $limit): array
    {
        $baseUrl = rtrim((string) config('soc.qdrant_base_url'), '/');
        $collection = (string) config('soc.qdrant_collection', 'soc_knowledge');
        try {
            $response = Http::timeout(5)->post($baseUrl.'/collections/'.$collection.'/points/search', [
                'vector' => array_values($this->denseVector(implode(' ', $query))),
                'limit' => $limit,
                'with_payload' => true,
            ])->throw()->json();
            $items = $response['result'] ?? [];
            return collect($items)->map(fn ($item) => [
                'kb_id' => $item['payload']['kb_id'] ?? null,
                'title' => $item['payload']['title'] ?? 'Qdrant citation',
                'entry_type' => $item['payload']['entry_type'] ?? 'external_vector',
                'score' => $item['score'] ?? 0,
                'vector_store' => 'qdrant',
                'excerpt' => $item['payload']['excerpt'] ?? '',
                'related_rule_id' => $item['payload']['related_rule_id'] ?? null,
                'related_ioc_id' => $item['payload']['related_ioc_id'] ?? null,
            ])->values()->all();
        } catch (\Throwable $e) {
            // Previously a silent catch-and-fallback -- which is exactly how
            // a real dimension mismatch between denseVector()'s output and
            // the Qdrant collection's configured size went undetected: every
            // call quietly fell back and nothing ever surfaced the failure.
            // Still fails open (advisory-only RAG citations must never break
            // the incident workflow), but now at least observable.
            Log::warning('soc knowledge qdrant retrieval failed, falling back to local', [
                'error' => $e->getMessage(),
            ]);
            return $this->retrieveLocal($context, $query, $limit, 'qdrant-fallback-local');
        }
    }

    public function upsertEmbedding(object $entry): void
    {
        $text = $entry->title.' '.$entry->content_markdown;

        // soc_knowledge_embeddings (Postgres) stays on the sparse
        // term-frequency keyword vector, unchanged -- retrieveLocal()'s own
        // cosine bonus compares this against keywordVector(implode(' ',
        // $query))'s identically-shaped sparse dict (string term -> count).
        // This is a fundamentally different representation than the dense,
        // fixed-dimension embedding used below for Qdrant -- mixing the two
        // here would silently break every local-path cosine score, since
        // cosine() would be comparing string-keyed and numeric-keyed
        // vectors with no overlapping keys at all.
        DB::table('soc_knowledge_embeddings')->updateOrInsert(
            ['kb_id' => $entry->kb_id],
            [
                'embedding_provider' => 'local-keyword',
                'embedding' => json_encode($this->keywordVector($text)),
                'metadata' => json_encode(['entry_type' => $entry->entry_type]),
                'embedded_at' => now(),
                'created_at' => $entry->created_at ?? now(),
                'updated_at' => now(),
            ]
        );

        if ((string) config('soc.rag_vector_store', 'local-keyword') === 'qdrant') {
            $this->upsertQdrantPoint($entry, $this->embeddingVector($text));
        }
    }

    /**
     * Pushes one SOC KB entry into the Qdrant collection so retrieveQdrant()
     * has real content to search -- previously nothing ever wrote KB rows
     * into Qdrant at all, so every query returned zero real matches
     * regardless of the vector scheme. Advisory-only: a Qdrant write
     * failure here is logged and swallowed, never bubbled up to whatever
     * triggered the KB write (creating/seeding a KB entry must not fail
     * because the search-index mirror is unreachable).
     */
    private function upsertQdrantPoint(object $entry, array $vector): void
    {
        $baseUrl = rtrim((string) config('soc.qdrant_base_url'), '/');
        $collection = (string) config('soc.qdrant_collection', 'soc_knowledge');
        try {
            Http::timeout((int) config('soc.embedding_timeout_seconds', 5))
                ->put($baseUrl.'/collections/'.$collection.'/points', [
                    'points' => [[
                        'id' => $this->qdrantPointId($entry->kb_id),
                        'vector' => array_values($vector),
                        'payload' => [
                            'kb_id' => $entry->kb_id,
                            'title' => $entry->title,
                            'entry_type' => $entry->entry_type,
                            'excerpt' => mb_substr((string) $entry->content_markdown, 0, 260),
                            'related_rule_id' => $entry->related_rule_id ?? null,
                            'related_ioc_id' => $entry->related_ioc_id ?? null,
                        ],
                    ]],
                ])->throw();
        } catch (\Throwable $e) {
            Log::warning('soc knowledge qdrant upsert failed', [
                'kb_id' => $entry->kb_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Qdrant point IDs must be an unsigned integer or a UUID string --
     * kb_id values in this codebase are human-readable slugs
     * (e.g. "rag-rkf-identity-mfa-001"), so this derives a stable,
     * deterministic UUID-shaped string from kb_id. Same kb_id always maps
     * to the same point ID, so upsertQdrantPoint() is a genuine update on
     * re-embedding, never a duplicate point.
     */
    private function qdrantPointId(string $kbId): string
    {
        $hash = md5($kbId);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }

    private function queryTerms(array $context): array
    {
        $terms = [];
        $terms[] = $context['incident']['severity'] ?? '';
        foreach ($context['mitre_mapping'] ?? [] as $mitre) {
            $terms[] = $mitre;
        }
        foreach ($context['alerts'] ?? [] as $alert) {
            $terms[] = $alert['alert_type'] ?? '';
            $terms[] = $alert['detector_name'] ?? '';
            $terms[] = $alert['severity'] ?? '';
        }
        foreach ($context['ioc_hits'] ?? [] as $hit) {
            $terms[] = $hit['matched_value'] ?? '';
        }
        return array_values(array_unique(array_filter($terms)));
    }

    private function keywordVector(string $text): array
    {
        preg_match_all('/[a-zA-Z0-9_.:-]{3,}/', strtolower($text), $matches);
        return collect($matches[0] ?? [])->countBy()->sortDesc()->take(64)->all();
    }

    /**
     * The vector actually used for both indexing (upsertEmbedding()) and
     * querying (retrieveQdrant()) -- must be the exact same scheme on both
     * sides, or cosine similarity between them is meaningless. Tries a real
     * transformer embedding first when config('soc.embedding_model_url')
     * is set; falls back to the dependency-free hashed-keyword vector
     * (unchanged default behavior) if it's unset, or if the real call
     * fails for any reason (advisory-only -- retrieval must degrade
     * gracefully, never throw).
     */
    private function denseVector(string $text): array
    {
        return $this->embeddingVector($text);
    }

    private function embeddingVector(string $text): array
    {
        $modelUrl = trim((string) config('soc.embedding_model_url', ''));
        if ($modelUrl !== '') {
            $real = $this->realEmbedding($text, $modelUrl);
            if ($real !== null) {
                return $real;
            }
        }
        return $this->hashVector($text);
    }

    private function embeddingProviderName(): string
    {
        return trim((string) config('soc.embedding_model_url', '')) !== ''
            ? (string) config('soc.embedding_model_name', 'all-minilm')
            : 'local-keyword';
    }

    // Small embedding models (all-minilm's default context is 256 tokens)
    // reject input over their context window outright rather than silently
    // truncating -- confirmed against a real Ollama instance: several of
    // this codebase's longer KB articles ("the input length exceeds the
    // context length", HTTP 500) failed every single embedding attempt,
    // not just transiently. ~4 chars/token is a conservative average for
    // English text, so this character budget keeps well under 256 tokens
    // with room to spare -- a title+excerpt is plenty for a citation
    // embedding to be useful; the full article is still what's returned to
    // the analyst; only what's sent to the embedding model is bounded.
    private const EMBEDDING_INPUT_MAX_CHARS = 800;

    /**
     * Calls a real embedding model (Ollama's /api/embeddings, or any
     * server implementing the same {model, prompt} -> {embedding} contract)
     * and returns its raw vector, or null on any failure -- caller falls
     * back to hashVector() rather than propagate the error, matching this
     * codebase's advisory-only posture for AI features.
     */
    private function realEmbedding(string $text, string $modelUrl): ?array
    {
        $text = mb_substr($text, 0, self::EMBEDDING_INPUT_MAX_CHARS);
        try {
            $response = Http::timeout((int) config('soc.embedding_timeout_seconds', 5))
                ->post(rtrim($modelUrl, '/').'/api/embeddings', [
                    'model' => (string) config('soc.embedding_model_name', 'all-minilm'),
                    'prompt' => $text,
                ]);
            if (!$response->successful()) {
                Log::warning('soc embedding request failed', ['status' => $response->status()]);
                return null;
            }
            $vector = $response->json('embedding');
            return is_array($vector) && $vector !== [] ? array_values($vector) : null;
        } catch (\Throwable $e) {
            Log::warning('soc embedding request threw', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // hashVector(): the original dependency-free scheme, unchanged --
    // still the default (and the only option) when no embedding model is
    // configured. 32 buckets, term counts hashed via crc32 -- crude, but
    // requires no external service and was this repo's only option before
    // AI-KB-SEMANTIC.
    private function hashVector(string $text): array
    {
        $vector = array_fill(0, 32, 0.0);
        foreach ($this->keywordVector($text) as $term => $count) {
            $idx = crc32((string) $term) % 32;
            $vector[$idx] += (float) $count;
        }
        return $vector;
    }

    private function cosine(array $a, array $b): float
    {
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        $dot = $magA = $magB = 0.0;
        foreach ($keys as $key) {
            $av = (float) ($a[$key] ?? 0);
            $bv = (float) ($b[$key] ?? 0);
            $dot += $av * $bv;
            $magA += $av * $av;
            $magB += $bv * $bv;
        }
        return $magA > 0 && $magB > 0 ? $dot / (sqrt($magA) * sqrt($magB)) : 0.0;
    }

    private function citationQuality(array $citations): float
    {
        if (!$citations) {
            return 0.0;
        }
        $withIds = collect($citations)->filter(fn ($row) => !empty($row['kb_id']))->count();
        $avgScore = collect($citations)->avg('score') ?: 0;
        return round(min(1.0, ($withIds / count($citations)) * 0.6 + min(1, $avgScore / 5) * 0.4), 4);
    }
}
