<?php

namespace App\Console\Commands;

use App\Support\SocKnowledgeRetriever;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AI-KB-SEMANTIC — backfill embeddings for existing SOC knowledge base
 * entries.
 *
 * SocKnowledgeRetriever::upsertEmbedding() only runs on new/updated KB
 * writes going forward (AiAnalystManager::ingestApprovedFeedback(),
 * SocKnowledgeBaseController::store()) -- entries seeded before this
 * command existed (e.g. via `ai:seed-knowledge`) never had an embedding
 * computed at all, for either the local-keyword cosine bonus
 * (soc_knowledge_embeddings) or the Qdrant vector search path. This
 * command closes that gap for whatever is already in the table.
 *
 * Read-only with respect to soc_knowledge_base itself (never mutates KB
 * content) -- only writes to soc_knowledge_embeddings and, when
 * config('soc.rag_vector_store')==='qdrant', the Qdrant collection.
 *
 * Usage:
 *   php artisan ai:embed-knowledge
 *   php artisan ai:embed-knowledge --kb-id=rag-rkf-identity-mfa-001
 */
class AiEmbedKnowledgeCommand extends Command
{
    protected $signature = 'ai:embed-knowledge
                            {--kb-id= : Re-embed a single entry instead of the whole table}';

    protected $description = 'Backfill embeddings for existing SOC knowledge base entries (AI-KB-SEMANTIC)';

    public function __construct(private readonly SocKnowledgeRetriever $retriever)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $store = (string) config('soc.rag_vector_store', 'local-keyword');
        $modelUrl = trim((string) config('soc.embedding_model_url', ''));

        $this->line('');
        $this->line('<fg=cyan>AI-KB-SEMANTIC — knowledge base embedding backfill</>');
        $this->line('  vector_store: '.$store);
        $this->line('  embedding_model_url: '.($modelUrl !== '' ? $modelUrl : '(unset — hashed-keyword fallback)'));
        $this->line(str_repeat('-', 60));

        $query = DB::table('soc_knowledge_base');
        if ($kbId = $this->option('kb-id')) {
            $query->where('kb_id', $kbId);
        }
        $entries = $query->get();

        if ($entries->isEmpty()) {
            $this->warn('No matching knowledge base entries found.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($entries->count());
        $bar->start();
        foreach ($entries as $entry) {
            $this->retriever->upsertEmbedding($entry);
            $bar->advance();
        }
        $bar->finish();
        $this->line('');
        $this->info("Embedded {$entries->count()} entries.");

        return self::SUCCESS;
    }
}
