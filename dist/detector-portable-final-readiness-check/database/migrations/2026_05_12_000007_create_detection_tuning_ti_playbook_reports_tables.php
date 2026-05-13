<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_feedback', function (Blueprint $table) {
            $table->id();
            $table->string('alert_id', 96)->index();
            $table->string('verdict', 32)->index();
            $table->string('reason', 160)->nullable();
            $table->text('notes')->nullable();
            $table->string('rule_id', 120)->nullable()->index();
            $table->string('analyst', 120)->index();
            $table->timestampTz('marked_at')->index();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('alert_suppression_rules', function (Blueprint $table) {
            $table->id();
            $table->string('suppression_id', 80)->unique();
            $table->string('scope', 32)->index();
            $table->string('match_value', 200)->index();
            $table->string('rule_id', 120)->nullable()->index();
            $table->text('reason')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->string('created_by', 120)->index();
            $table->timestampsTz();
        });

        Schema::create('alert_suppression_history', function (Blueprint $table) {
            $table->id();
            $table->string('suppression_id', 80)->nullable()->index();
            $table->string('alert_id', 96)->index();
            $table->string('suppressed_by', 120)->default('system')->index();
            $table->timestampTz('suppressed_at')->index();
            $table->string('reason', 240)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('rule_tuning_notes', function (Blueprint $table) {
            $table->id();
            $table->string('rule_id', 120)->index();
            $table->text('note');
            $table->string('suggestion_type', 80)->nullable()->index();
            $table->string('analyst', 120)->index();
            $table->timestampsTz();
        });

        Schema::create('threat_iocs', function (Blueprint $table) {
            $table->id();
            $table->string('ioc_id', 80)->unique();
            $table->string('ioc_type', 32)->index();
            $table->string('ioc_value', 512)->index();
            $table->string('source', 160)->nullable()->index();
            $table->string('reputation', 32)->default('unknown')->index();
            $table->string('threat_label', 160)->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->boolean('enabled')->default(true)->index();
            $table->jsonb('metadata')->nullable();
            $table->string('created_by', 120)->default('system')->index();
            $table->timestampsTz();
            $table->unique(['ioc_type', 'ioc_value']);
        });

        Schema::create('ioc_hits', function (Blueprint $table) {
            $table->id();
            $table->string('ioc_id', 80)->index();
            $table->string('alert_id', 96)->nullable()->index();
            $table->unsignedBigInteger('telemetry_event_id')->nullable()->index();
            $table->string('matched_field', 80)->nullable();
            $table->string('matched_value', 512)->nullable();
            $table->timestampTz('matched_at')->index();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('incident_playbooks', function (Blueprint $table) {
            $table->id();
            $table->string('playbook_id', 80)->unique();
            $table->string('incident_id', 80)->nullable()->index();
            $table->string('name', 180);
            $table->string('incident_type', 120)->nullable()->index();
            $table->string('status', 32)->default('active')->index();
            $table->string('assigned_analyst', 120)->nullable()->index();
            $table->unsignedInteger('progress_percent')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->string('created_by', 120)->index();
            $table->timestampsTz();
        });

        Schema::create('incident_playbook_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_id', 80)->unique();
            $table->string('playbook_id', 80)->index();
            $table->string('task_type', 80)->default('checklist')->index();
            $table->string('title', 220);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->string('assigned_to', 120)->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('executive_security_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id', 80)->unique();
            $table->string('period', 32)->index();
            $table->timestampTz('period_start')->index();
            $table->timestampTz('period_end')->index();
            $table->jsonb('summary');
            $table->string('generated_by', 120)->default('system')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_security_reports');
        Schema::dropIfExists('incident_playbook_tasks');
        Schema::dropIfExists('incident_playbooks');
        Schema::dropIfExists('ioc_hits');
        Schema::dropIfExists('threat_iocs');
        Schema::dropIfExists('rule_tuning_notes');
        Schema::dropIfExists('alert_suppression_history');
        Schema::dropIfExists('alert_suppression_rules');
        Schema::dropIfExists('alert_feedback');
    }
};
