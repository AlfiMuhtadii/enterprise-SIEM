<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_id', 80)->unique();
            $table->string('title', 200);
            $table->string('status', 32)->default('open')->index();
            $table->string('severity', 16)->default('medium')->index();
            $table->float('confidence')->nullable();
            $table->string('assigned_analyst', 120)->nullable()->index();
            $table->timestampTz('first_seen_at')->index();
            $table->timestampTz('last_seen_at')->index();
            $table->jsonb('affected_entities')->nullable();
            $table->jsonb('timeline')->nullable();
            $table->jsonb('mitre_mapping')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('security_incident_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('incident_id', 80)->index();
            $table->string('alert_id', 96)->index();
            $table->timestampsTz();
            $table->unique(['incident_id', 'alert_id']);
        });

        Schema::create('security_incident_notes', function (Blueprint $table) {
            $table->id();
            $table->string('incident_id', 80)->index();
            $table->string('author', 120)->nullable();
            $table->string('note_type', 40)->default('note')->index();
            $table->text('body');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incident_notes');
        Schema::dropIfExists('security_incident_alerts');
        Schema::dropIfExists('security_incidents');
    }
};
