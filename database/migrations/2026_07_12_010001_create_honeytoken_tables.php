<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mutable: seeded fake indicators (credential/file_path/url/dns_name) an analyst plants
        // to get a high-signal, near-zero-FP tripwire — any legitimate touch is definitionally
        // an incident, since nothing should ever reference these values.
        Schema::create('honeytokens', function (Blueprint $table) {
            $table->id();
            $table->string('honeytoken_id')->unique();
            $table->string('token_type', 32); // credential | file_path | url | dns_name
            $table->string('token_value', 512);
            $table->string('label')->nullable();
            $table->string('tenant_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('created_by', 120)->default('system');
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->index('token_type');
            $table->index('is_active');
            $table->index('tenant_id');
        });

        // Append-only: every confirmed touch of a honeytoken value found in alert evidence.
        // Purely detective — no offensive deployment, no active response triggered from a hit.
        Schema::create('honeytoken_hits', function (Blueprint $table) {
            $table->id();
            $table->string('honeytoken_id');
            $table->string('alert_id', 96)->nullable();
            $table->unsignedBigInteger('telemetry_event_id')->nullable();
            $table->string('matched_field', 80)->nullable();
            $table->string('matched_value', 512)->nullable();
            $table->timestampTz('matched_at');
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['honeytoken_id', 'alert_id']);
            $table->index('honeytoken_id');
            $table->index('matched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('honeytoken_hits');
        Schema::dropIfExists('honeytokens');
    }
};
