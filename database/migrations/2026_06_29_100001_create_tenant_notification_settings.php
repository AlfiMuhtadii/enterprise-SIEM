<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NOTIFY-TENANCY-GAP: per-tenant notification targets (mutable, isolated).
        // One row per tenant; null/absent row => fall back to global config
        // (config/notifications_soc.php) for backward-compatible demo behavior.
        Schema::create('tenant_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 36)->unique();
            $table->text('webhook_url')->nullable();
            $table->text('slack_url')->nullable();
            $table->text('discord_url')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestampsTz();
        });

        // NOTIFY-TENANCY-GAP: scope the delivery audit trail by tenant.
        if (!Schema::hasColumn('notification_delivery_logs', 'tenant_id')) {
            Schema::table('notification_delivery_logs', function (Blueprint $table) {
                $table->string('tenant_id', 36)->nullable()->index()->after('incident_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('notification_delivery_logs', 'tenant_id')) {
            Schema::table('notification_delivery_logs', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }

        Schema::dropIfExists('tenant_notification_settings');
    }
};
