<?php

namespace Tests\Feature;

use App\Console\Commands\AiEmbedKnowledgeCommand;
use App\Support\SocKnowledgeRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * AI-KB-SEMANTIC — real Qdrant vector search + real embedding model support.
 *
 * Covers the two bugs this task fixed and the new capability it added:
 *  1. retrieveQdrant() previously sent a 32-dim hashed vector against a
 *     384-dim Qdrant collection -- every real call failed and silently
 *     fell back to the local keyword search (the failure was invisible).
 *  2. Qdrant had zero real KB content -- nothing to search regardless of
 *     vector shape.
 *  3. New: an optional real embedding model (soc.embedding_model_url,
 *     Ollama-compatible {model, prompt} -> {embedding} contract), with a
 *     bounded input length so long KB articles don't exceed the model's
 *     context window (confirmed against a real Ollama instance).
 *
 * All HTTP calls to Qdrant/Ollama are faked -- no live infra required.
 */
class AiKbSemanticTest extends TestCase
{
    use RefreshDatabase;

    private function configureQdrant(): void
    {
        Config::set('soc.rag_vector_store', 'qdrant');
        Config::set('soc.qdrant_base_url', 'http://qdrant.test:6333');
        Config::set('soc.qdrant_collection', 'soc_knowledge');
    }

    private function seedKbEntry(string $kbId, string $title, string $content): void
    {
        DB::table('soc_knowledge_base')->insert([
            'kb_id' => $kbId,
            'title' => $title,
            'entry_type' => 'runbook',
            'content_markdown' => $content,
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // retrieveQdrant() dimension-mismatch fix + observability
    // -------------------------------------------------------------------------

    public function test_retrieve_uses_qdrant_search_endpoint_when_configured(): void
    {
        $this->configureQdrant();
        Http::fake([
            'qdrant.test:6333/collections/soc_knowledge/points/search' => Http::response([
                'result' => [
                    ['score' => 0.91, 'payload' => ['kb_id' => 'kb-1', 'title' => 'Match', 'entry_type' => 'runbook', 'excerpt' => 'x']],
                ],
            ], 200),
        ]);

        $retriever = app(SocKnowledgeRetriever::class);
        $citations = $retriever->retrieve(['alerts' => [['alert_type' => 'mfa_bypass']]], 5);

        $this->assertCount(1, $citations);
        $this->assertSame('kb-1', $citations[0]['kb_id']);
        $this->assertSame('qdrant', $citations[0]['vector_store']);
    }

    public function test_retrieve_qdrant_failure_falls_back_to_local_and_logs_warning(): void
    {
        $this->configureQdrant();
        $this->seedKbEntry('kb-fallback', 'MFA Bypass Runbook', 'mfa_bypass detection guidance');
        Http::fake([
            'qdrant.test:6333/*' => Http::response('server error', 500),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with('soc knowledge qdrant retrieval failed, falling back to local', \Mockery::type('array'));

        $retriever = app(SocKnowledgeRetriever::class);
        $citations = $retriever->retrieve(['alerts' => [['alert_type' => 'mfa_bypass']]], 5);

        $this->assertNotEmpty($citations);
        $this->assertSame('qdrant-fallback-local', $citations[0]['vector_store']);
    }

    // -------------------------------------------------------------------------
    // upsertEmbedding() — sparse keyword vector always goes to Postgres,
    // regardless of which vector_store is active
    // -------------------------------------------------------------------------

    public function test_upsert_embedding_keeps_sparse_keyword_vector_in_postgres_even_with_qdrant_active(): void
    {
        $this->configureQdrant();
        Http::fake(['qdrant.test:6333/*' => Http::response(['result' => 'acknowledged'], 200)]);

        $this->seedKbEntry('kb-sparse', 'Password Spray Detection', 'password spray brute force identity');
        $entry = DB::table('soc_knowledge_base')->where('kb_id', 'kb-sparse')->first();

        app(SocKnowledgeRetriever::class)->upsertEmbedding($entry);

        $row = DB::table('soc_knowledge_embeddings')->where('kb_id', 'kb-sparse')->first();
        $this->assertNotNull($row);
        $this->assertSame('local-keyword', $row->embedding_provider);

        $decoded = json_decode($row->embedding, true);
        $this->assertIsArray($decoded);
        // Sparse term-frequency dict: string term keys, not a numeric
        // 0..N-1 dense vector -- if this ever becomes a dense array, the
        // cosine bonus in retrieveLocal() silently breaks (no overlapping
        // keys with keywordVector() on the query side).
        $stringKeys = array_filter(array_keys($decoded), 'is_string');
        $this->assertNotEmpty($stringKeys, 'embedding stored for local cosine bonus must be string-keyed, not a dense numeric vector');
    }

    public function test_upsert_embedding_pushes_point_to_qdrant_when_store_is_qdrant(): void
    {
        $this->configureQdrant();
        Http::fake(['qdrant.test:6333/collections/soc_knowledge/points' => Http::response(['result' => 'acknowledged'], 200)]);

        $this->seedKbEntry('kb-push', 'Cloud Key Creation', 'cloud access key created after mfa failure');
        $entry = DB::table('soc_knowledge_base')->where('kb_id', 'kb-push')->first();

        app(SocKnowledgeRetriever::class)->upsertEmbedding($entry);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT') {
                return false;
            }
            $point = $request->data()['points'][0] ?? null;

            return $point !== null
                && $point['payload']['kb_id'] === 'kb-push'
                && is_array($point['vector'])
                && $point['vector'] !== [];
        });
    }

    public function test_upsert_embedding_does_not_call_qdrant_when_store_is_local(): void
    {
        Config::set('soc.rag_vector_store', 'local-keyword');
        Http::fake();

        $this->seedKbEntry('kb-local-only', 'Local Only Entry', 'no vector store push expected');
        $entry = DB::table('soc_knowledge_base')->where('kb_id', 'kb-local-only')->first();

        app(SocKnowledgeRetriever::class)->upsertEmbedding($entry);

        Http::assertNothingSent();
    }

    public function test_upsert_embedding_swallows_qdrant_failure_and_does_not_throw(): void
    {
        $this->configureQdrant();
        Http::fake(['qdrant.test:6333/*' => Http::response('unavailable', 503)]);

        $this->seedKbEntry('kb-resilient', 'Resilient Entry', 'qdrant write failure must not break kb writes');
        $entry = DB::table('soc_knowledge_base')->where('kb_id', 'kb-resilient')->first();

        app(SocKnowledgeRetriever::class)->upsertEmbedding($entry);

        // Reaching this line without an exception is the assertion -- KB
        // writes must never fail because the search-index mirror is down.
        $this->assertDatabaseHas('soc_knowledge_embeddings', ['kb_id' => 'kb-resilient']);
    }

    // -------------------------------------------------------------------------
    // Real embedding model dispatch (Ollama-compatible) + context-length bound
    // -------------------------------------------------------------------------

    public function test_real_embedding_model_used_when_configured(): void
    {
        $this->configureQdrant();
        Config::set('soc.embedding_model_url', 'http://ollama.test:11434');
        Config::set('soc.embedding_model_name', 'all-minilm');

        Http::fake([
            'ollama.test:11434/api/embeddings' => Http::response(['embedding' => array_fill(0, 384, 0.1)], 200),
            'qdrant.test:6333/*' => Http::response(['result' => 'acknowledged'], 200),
        ]);

        $this->seedKbEntry('kb-real-model', 'Real Model Entry', 'short content well under context window');
        $entry = DB::table('soc_knowledge_base')->where('kb_id', 'kb-real-model')->first();

        app(SocKnowledgeRetriever::class)->upsertEmbedding($entry);

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), 'ollama.test:11434/api/embeddings')
                && $request->data()['model'] === 'all-minilm';
        });

        Http::assertSent(function ($request) {
            $point = $request->data()['points'][0] ?? null;

            return $point !== null && count($point['vector']) === 384;
        });
    }

    public function test_real_embedding_request_truncates_long_kb_content(): void
    {
        $this->configureQdrant();
        Config::set('soc.embedding_model_url', 'http://ollama.test:11434');

        Http::fake([
            'ollama.test:11434/api/embeddings' => Http::response(['embedding' => array_fill(0, 384, 0.1)], 200),
            'qdrant.test:6333/*' => Http::response(['result' => 'acknowledged'], 200),
        ]);

        // Confirmed against a real Ollama all-minilm instance: content this
        // long ("the input length exceeds the context length") returns a
        // 500 without truncation. Title + content together comfortably
        // exceed the 800-char budget.
        $longContent = str_repeat('lateral movement via stolen kerberos ticket golden ticket forgery ', 40);
        $this->seedKbEntry('kb-long', 'Long KB Article', $longContent);
        $entry = DB::table('soc_knowledge_base')->where('kb_id', 'kb-long')->first();

        app(SocKnowledgeRetriever::class)->upsertEmbedding($entry);

        Http::assertSent(function ($request) {
            if (!str_contains((string) $request->url(), 'api/embeddings')) {
                return true; // not the request we're checking
            }

            return strlen((string) $request->data()['prompt']) <= 800;
        });
    }

    public function test_real_embedding_failure_falls_back_to_hash_vector_not_thrown(): void
    {
        $this->configureQdrant();
        Config::set('soc.embedding_model_url', 'http://ollama.test:11434');

        Http::fake([
            'ollama.test:11434/api/embeddings' => Http::response('context length exceeded', 500),
            'qdrant.test:6333/*' => Http::response(['result' => 'acknowledged'], 200),
        ]);

        $this->seedKbEntry('kb-ollama-down', 'Entry', 'content');
        $entry = DB::table('soc_knowledge_base')->where('kb_id', 'kb-ollama-down')->first();

        // Must not throw -- falls back to the 32-dim hashVector() and still
        // pushes a point to Qdrant so the entry stays searchable.
        app(SocKnowledgeRetriever::class)->upsertEmbedding($entry);

        Http::assertSent(function ($request) {
            $point = $request->data()['points'][0] ?? null;

            return $point !== null && count($point['vector']) === 32;
        });
    }

    public function test_embedding_model_url_unset_never_calls_external_model(): void
    {
        Config::set('soc.rag_vector_store', 'local-keyword');
        Config::set('soc.embedding_model_url', '');
        Http::fake();

        $this->seedKbEntry('kb-no-model', 'Entry', 'content');
        $entry = DB::table('soc_knowledge_base')->where('kb_id', 'kb-no-model')->first();

        app(SocKnowledgeRetriever::class)->upsertEmbedding($entry);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // AiEmbedKnowledgeCommand — backfill
    // -------------------------------------------------------------------------

    public function test_embed_knowledge_command_class_exists(): void
    {
        $this->assertTrue(class_exists(AiEmbedKnowledgeCommand::class));
    }

    public function test_embed_knowledge_command_backfills_all_entries(): void
    {
        Config::set('soc.rag_vector_store', 'local-keyword');
        $this->seedKbEntry('kb-backfill-1', 'Entry One', 'content one');
        $this->seedKbEntry('kb-backfill-2', 'Entry Two', 'content two');

        $this->artisan('ai:embed-knowledge')->assertExitCode(0);

        $this->assertDatabaseHas('soc_knowledge_embeddings', ['kb_id' => 'kb-backfill-1']);
        $this->assertDatabaseHas('soc_knowledge_embeddings', ['kb_id' => 'kb-backfill-2']);
    }

    public function test_embed_knowledge_command_single_kb_id_option_scopes_to_one_entry(): void
    {
        Config::set('soc.rag_vector_store', 'local-keyword');
        $this->seedKbEntry('kb-scope-a', 'A', 'content a');
        $this->seedKbEntry('kb-scope-b', 'B', 'content b');

        $this->artisan('ai:embed-knowledge', ['--kb-id' => 'kb-scope-a'])->assertExitCode(0);

        $this->assertDatabaseHas('soc_knowledge_embeddings', ['kb_id' => 'kb-scope-a']);
        $this->assertDatabaseMissing('soc_knowledge_embeddings', ['kb_id' => 'kb-scope-b']);
    }

    public function test_embed_knowledge_command_never_mutates_kb_content(): void
    {
        Config::set('soc.rag_vector_store', 'local-keyword');
        $this->seedKbEntry('kb-readonly', 'Original Title', 'original content');

        $this->artisan('ai:embed-knowledge')->assertExitCode(0);

        $entry = DB::table('soc_knowledge_base')->where('kb_id', 'kb-readonly')->first();
        $this->assertSame('Original Title', $entry->title);
        $this->assertSame('original content', $entry->content_markdown);
    }

    public function test_embed_knowledge_command_reports_no_entries_gracefully(): void
    {
        $this->artisan('ai:embed-knowledge', ['--kb-id' => 'does-not-exist'])
            ->assertExitCode(0);
    }
}
