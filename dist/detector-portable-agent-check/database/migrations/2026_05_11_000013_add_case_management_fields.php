<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_incidents', function (Blueprint $table) {
            if (!Schema::hasColumn('security_incidents', 'sla_due_at')) {
                $table->timestampTz('sla_due_at')->nullable()->after('assigned_analyst')->index();
            }
            if (!Schema::hasColumn('security_incidents', 'escalation_level')) {
                $table->unsignedSmallInteger('escalation_level')->default(0)->after('sla_due_at')->index();
            }
            if (!Schema::hasColumn('security_incidents', 'resolution_summary')) {
                $table->text('resolution_summary')->nullable()->after('metadata');
            }
            if (!Schema::hasColumn('security_incidents', 'resolved_at')) {
                $table->timestampTz('resolved_at')->nullable()->after('resolution_summary')->index();
            }
        });

        Schema::create('security_incident_activities', function (Blueprint $table) {
            $table->id();
            $table->string('incident_id', 80)->index();
            $table->string('actor', 120)->default('system')->index();
            $table->string('activity_type', 80)->index();
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['incident_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incident_activities');

        Schema::table('security_incidents', function (Blueprint $table) {
            foreach (['sla_due_at', 'escalation_level', 'resolution_summary', 'resolved_at'] as $col) {
                if (Schema::hasColumn('security_incidents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
