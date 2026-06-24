<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('easm_finding_changes', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('asset_id');
            $table->string('run_id');
            $table->string('finding_key');
            $table->string('change_type', 20); // new | resolved | unchanged | regressed
            $table->string('severity_before', 20)->nullable();
            $table->string('severity_after', 20)->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('asset_id');
            $table->index('change_type');
            $table->index(['asset_id', 'run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('easm_finding_changes');
    }
};
