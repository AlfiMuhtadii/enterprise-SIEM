<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advisory_findings', function (Blueprint $table) {
            $table->enum('review_outcome', ['confirmed_detection', 'false_positive', 'inconclusive'])->nullable();
            $table->index(['tenant_id', 'rule_id', 'first_seen_at'], 'advisory_findings_review_cohort_idx');
        });
    }

    public function down(): void
    {
        Schema::table('advisory_findings', function (Blueprint $table) {
            $table->dropIndex('advisory_findings_review_cohort_idx');
            $table->dropColumn('review_outcome');
        });
    }
};
