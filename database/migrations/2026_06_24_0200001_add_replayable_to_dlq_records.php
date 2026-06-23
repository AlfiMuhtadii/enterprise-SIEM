<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dlq_records', function (Blueprint $table) {
            // Explicit replayability classification written by consumers.
            // true  = error class is transient and could benefit from retry
            // false = structural failure (parse error, malformed event) — not retryable
            // Distinct from isReplayable() which also gates on raw_payload presence.
            $table->boolean('replayable')->default(false)->after('tenant_id');

            // Freeform error reason code sourced from structured failure envelope.
            // Complements dlq_event_type with the reason field from the producer.
            $table->string('error_reason')->nullable()->after('replayable');
        });
    }

    public function down(): void
    {
        Schema::table('dlq_records', function (Blueprint $table) {
            $table->dropColumn(['replayable', 'error_reason']);
        });
    }
};
