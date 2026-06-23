<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mutable — tracks current review/replay state per unique DLQ record.
        // status transitions: new → reviewed|ignored|replay_requested → replayed|replay_failed
        // replay_requested requires raw_payload IS NOT NULL (enforced in service layer)
        Schema::create('dlq_records', function (Blueprint $table) {
            $table->id();
            $table->string('record_id')->unique();
            $table->string('dlq_event_type')->default('unknown');
            $table->string('source_topic')->nullable();
            $table->integer('source_partition')->nullable();
            $table->bigInteger('source_offset')->nullable();
            $table->string('consumer_group')->nullable();
            $table->integer('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('raw_payload')->nullable();
            $table->timestamp('isolated_at')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('status')->default('new');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedBigInteger('replay_requested_by')->nullable();
            $table->timestamp('replay_requested_at')->nullable();
            $table->timestamp('replayed_at')->nullable();
            $table->jsonb('replay_result')->nullable();
            $table->string('fingerprint')->unique();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index('status');
            $table->index('dlq_event_type');
            $table->index('tenant_id');
            $table->index('last_seen_at');
            $table->index('source_topic');
        });

        // Append-only audit trail for DLQ normalization review actions.
        // Separate from dlq_replay_events (operations hardening replay jobs — different schema).
        // Never UPDATE or DELETE records in this table — it is an immutable audit log.
        Schema::create('dlq_normalization_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('record_id');
            $table->string('event_type');
            $table->unsignedBigInteger('analyst_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('record_id');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dlq_normalization_events');
        Schema::dropIfExists('dlq_records');
    }
};
