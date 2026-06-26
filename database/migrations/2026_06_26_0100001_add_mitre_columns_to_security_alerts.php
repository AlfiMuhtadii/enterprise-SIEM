<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            $table->string('mitre_tactic', 64)->nullable()->after('model_label');
            $table->string('mitre_technique_id', 20)->nullable()->after('mitre_tactic');
            $table->string('mitre_technique_name', 128)->nullable()->after('mitre_technique_id');
        });
    }

    public function down(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            $table->dropColumn(['mitre_tactic', 'mitre_technique_id', 'mitre_technique_name']);
        });
    }
};
