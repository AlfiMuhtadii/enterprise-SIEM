<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenario_evidence', function (Blueprint $table) {
            $table->integer('latency_ms')->nullable()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('scenario_evidence', function (Blueprint $table) {
            $table->dropColumn('latency_ms');
        });
    }
};
