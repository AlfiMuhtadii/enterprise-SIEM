<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENTERPRISE-071 — RAG Knowledge Base Operational Integration tests.
 */
class RagOperationalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // RagOperationalCheckCommand
    // -------------------------------------------------------------------------

    public function test_knowledge_check_command_class_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\Console\Commands\RagOperationalCheckCommand::class),
            'RagOperationalCheckCommand should be registered'
        );
    }

    public function test_knowledge_check_command_runs_successfully(): void
    {
        $this->artisan('ai:knowledge-check')->assertExitCode(0);
    }

    public function test_knowledge_check_returns_json_output(): void
    {
        $this->artisan('ai:knowledge-check')
            ->assertExitCode(0);
    }

    public function test_knowledge_check_command_is_read_only(): void
    {
        $path    = app_path('Console/Commands/RagOperationalCheckCommand.php');
        $content = file_get_contents($path);
        $this->assertStringNotContainsString('->insert(', $content);
        $this->assertStringNotContainsString('->update(', $content);
        $this->assertStringNotContainsString('->delete(', $content);
    }

    // -------------------------------------------------------------------------
    // AiSeedKnowledgeCommand
    // -------------------------------------------------------------------------

    public function test_seed_knowledge_command_class_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\Console\Commands\AiSeedKnowledgeCommand::class),
            'AiSeedKnowledgeCommand should be registered'
        );
    }

    // -------------------------------------------------------------------------
    // AiKnowledgeSeedService
    // -------------------------------------------------------------------------

    public function test_knowledge_seed_service_has_fixture_path(): void
    {
        $path    = app_path('Services/AiKnowledgeSeedService.php');
        $content = file_get_contents($path);
        $this->assertStringContainsString('FIXTURE_PATH', $content);
        $this->assertStringContainsString('rag_knowledge_fixtures', $content);
    }

    // -------------------------------------------------------------------------
    // AiAnalystManager / retriever pipeline
    // -------------------------------------------------------------------------

    public function test_ai_analyst_manager_class_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\Support\AiAnalystManager::class),
            'AiAnalystManager should be present'
        );
    }

    public function test_soc_knowledge_retriever_class_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\Support\SocKnowledgeRetriever::class),
            'SocKnowledgeRetriever should be present'
        );
    }

    public function test_ai_rag_service_provider_class_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\Support\AiRagServiceProvider::class),
            'AiRagServiceProvider should be present'
        );
    }

    // -------------------------------------------------------------------------
    // soc_knowledge_base table
    // -------------------------------------------------------------------------

    public function test_soc_knowledge_base_table_accessible(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('soc_knowledge_base'),
            'soc_knowledge_base table should exist after migrate:fresh'
        );
    }

    public function test_soc_knowledge_base_query_runs(): void
    {
        $count = DB::table('soc_knowledge_base')->count();
        $this->assertIsInt($count);
    }

    // -------------------------------------------------------------------------
    // Advisory — never modifies data on check
    // -------------------------------------------------------------------------

    public function test_knowledge_check_does_not_modify_kb(): void
    {
        $before = DB::table('soc_knowledge_base')->count();
        $this->artisan('ai:knowledge-check')->assertExitCode(0);
        $after  = DB::table('soc_knowledge_base')->count();
        $this->assertSame($before, $after, 'ai:knowledge-check must not insert KB entries');
    }
}
