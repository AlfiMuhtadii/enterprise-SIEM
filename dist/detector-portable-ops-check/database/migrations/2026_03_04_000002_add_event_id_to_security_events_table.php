<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            if (!Schema::hasColumn('security_events', 'event_id')) {
                $table->string('event_id', 64)->nullable()->after('event_type');
                $table->unique('event_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            if (Schema::hasColumn('security_events', 'event_id')) {
                $table->dropUnique(['event_id']);
                $table->dropColumn('event_id');
            }
        });
    }
};
