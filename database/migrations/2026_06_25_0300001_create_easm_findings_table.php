<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('easm_findings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('asset_id');
            $table->string('finding_key');
            $table->string('category', 50);
            $table->string('severity', 20)->default('info');
            $table->string('confidence', 20)->default('medium');
            $table->string('title');
            $table->text('description');
            $table->json('evidence')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('scan_policy', 30)->default('passive');
            $table->string('source', 50)->default('easm_passive');
            $table->string('status', 20)->default('open');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->boolean('is_advisory')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'asset_id', 'finding_key']);
            $table->index('tenant_id');
            $table->index('asset_id');
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('easm_findings');
    }
};
