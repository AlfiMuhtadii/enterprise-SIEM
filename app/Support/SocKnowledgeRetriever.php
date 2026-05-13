<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SocKnowledgeRetriever
{
    public function retrieve(array $context, int $limit = 6): array
    {
        $started = microtime(true);
        $query = $this->queryTerms($context);
        $store = (string) config('soc.rag_vector_store', 'local-keyword');
        $embeddingProvider = (string) config('soc.rag_embedding_provider', 'local-keyword');
        if ($store === 'qdrant') {
            $citations = $this->retrieveQdrant($context, $query, $limit);
        } else {
            $citations = $this->retrieveLocal($context, $query, $limit, $store);
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
        } catch (\Throwable) {
            return $this->retrieveLocal($context, $query, $limit, 'qdrant-fallback-local');
        }
    }

    public function upsertEmbedding(object $entry): void
    {
        DB::table('soc_knowledge_embeddings')->updateOrInsert(
            ['kb_id' => $entry->kb_id],
            [
                'embedding_provider' => 'local-keyword',
                'embedding' => json_encode($this->keywordVector($entry->title.' '.$entry->content_markdown)),
                'metadata' => json_encode(['entry_type' => $entry->entry_type]),
                'embedded_at' => now(),
                'created_at' => $entry->created_at ?? now(),
                'updated_at' => now(),
            ]
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

    private function denseVector(string $text): array
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
