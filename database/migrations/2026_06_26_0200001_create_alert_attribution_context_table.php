<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: no UPDATE/DELETE permitted.
        // Advisory OSINT enrichment per alert — never triggers automated response.
        Schema::create('alert_attribution_context', function (Blueprint $table) {
            $table->id();
            $table->string('attribution_id', 64)->unique();
            $table->string('alert_id', 64)->index();
            $table->string('alert_type', 64)->index();
            $table->string('ip', 45)->nullable()->index();
            $table->string('country_code', 16)->nullable();
            $table->string('country_name', 128)->nullable();
            $table->string('asn', 32)->nullable();
            $table->string('asn_org', 128)->nullable();
            $table->string('ip_type', 32)->nullable();
            $table->string('ip_reputation_hint', 128)->nullable();
            $table->string('threat_actor_hint', 128)->nullable();
            $table->float('confidence')->default(0.5);
            $table->string('enrichment_source', 64)->default('offline_fixture');
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_attribution_context');
    }
};
