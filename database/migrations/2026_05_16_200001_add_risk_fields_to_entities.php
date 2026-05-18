<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('risk_level', 20)->nullable()->index()->after('risk_score');
            $table->jsonb('risk_factors')->nullable()->after('risk_level');
            $table->timestamp('last_risk_calculated_at')->nullable()->index()->after('risk_factors');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn(['risk_level', 'risk_factors', 'last_risk_calculated_at']);
        });
    }
};
