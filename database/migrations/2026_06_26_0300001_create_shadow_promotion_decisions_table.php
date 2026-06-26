<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. promotion_approved is ALWAYS false — actual promotion requires
        // ACTIVE_ALLOWLIST + domain-specific 6h soak PASS + human sign-off.
        // NEVER UPDATE or DELETE rows in this table.
        Schema::create('shadow_promotion_decisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('decision_run_id')->index();
            $table->string('rule_id', 128);
            $table->string('domain', 64);
            $table->string('current_status', 32)->default('shadow');
            $table->float('confidence')->nullable();
            $table->string('decision', 32);        // promote_eligible | keep_shadow | defer
            $table->string('false_positive_risk', 16)->default('unknown');
            $table->unsignedInteger('dlq_errors_in_domain')->default(0);
            $table->unsignedInteger('advisory_findings_count')->default(0);
            $table->jsonb('evidence_basis')->nullable();
            $table->boolean('promotion_approved')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('evaluated_at')->useCurrent();

            $table->index(['rule_id', 'evaluated_at']);
            $table->index(['domain', 'decision']);
            $table->index('decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_promotion_decisions');
    }
};
