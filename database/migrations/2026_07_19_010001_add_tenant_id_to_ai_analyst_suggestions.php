<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI-2: ai_analyst_suggestions had no tenant_id at all. SocAiController's
        // generate()/review() only checked the target incident's *existence*
        // (not ownership) and looked up suggestions globally by suggestion_id,
        // so any authenticated user could request an AI suggestion against any
        // tenant's incident, or review/accept-into-knowledge-base any other
        // tenant's pending suggestion.
        Schema::table('ai_analyst_suggestions', function (Blueprint $table) {
            $table->string('tenant_id', 36)->nullable()->index()->after('target_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_analyst_suggestions', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};
