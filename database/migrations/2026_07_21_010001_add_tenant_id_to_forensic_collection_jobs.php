<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TENANT-FORENSIC-ISOLATION: forensic_collection_jobs had no
        // tenant_id at all -- request()/decide()/buildArtifact() all ran
        // completely unscoped, letting one tenant's analyst trigger,
        // approve, or view another tenant's forensic collection jobs.
        Schema::table('forensic_collection_jobs', function (Blueprint $table) {
            $table->string('tenant_id', 36)->nullable()->after('id');
            $table->index(['tenant_id', 'created_at'], 'fcj_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('forensic_collection_jobs', function (Blueprint $table) {
            $table->dropIndex('fcj_tenant_created_idx');
            $table->dropColumn('tenant_id');
        });
    }
};
