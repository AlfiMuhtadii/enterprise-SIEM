<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Denormalized from the owning honeytoken at scan time (HoneytokenService::scanForHits())
        // so a hit can be tenant-scoped without a join -- honeytoken_hits stays append-only.
        Schema::table('honeytoken_hits', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('alert_id');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('honeytoken_hits', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};
