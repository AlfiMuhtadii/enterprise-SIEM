<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mutable: fixture definitions (update allowed — tracks fixture revisions)
        Schema::create('rag_seed_fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('fixture_id')->unique();
            $table->string('title');
            $table->string('category');           // detection_rule | soc_procedure | incident_type | threat_intel
            $table->text('content');
            $table->string('source')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Append-only seeding run records
        Schema::create('rag_seed_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('initiated_by')->nullable();
            $table->boolean('dry_run')->default(true);
            $table->integer('fixtures_total')->default(0);
            $table->integer('fixtures_seeded')->default(0);
            $table->integer('fixtures_skipped')->default(0);
            $table->integer('fixtures_failed')->default(0);
            $table->string('outcome');            // DONE | DRY_RUN | PARTIAL | FAILED
            $table->timestamps();
        });

        // Append-only document-level seed log
        Schema::create('rag_seed_document_log', function (Blueprint $table) {
            $table->id();
            $table->string('run_id');
            $table->string('fixture_id');
            $table->string('kb_entry_id')->nullable();  // soc_knowledge_base id
            $table->string('action');             // SEEDED | SKIPPED | FAILED
            $table->text('detail')->nullable();
            $table->timestamps();

            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_seed_document_log');
        Schema::dropIfExists('rag_seed_runs');
        Schema::dropIfExists('rag_seed_fixtures');
    }
};
