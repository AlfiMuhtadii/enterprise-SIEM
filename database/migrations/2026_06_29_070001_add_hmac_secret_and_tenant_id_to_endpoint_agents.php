<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('endpoint_agents', function (Blueprint $table) {
            // CMD-SHARED-HMAC: per-agent HMAC secret; null = fall back to shared enrollment token
            $table->text('hmac_secret')->nullable()->after('agent_secret');
            // AGENT-TENANCY-GAP: tenant scoping for endpoint fleet
            $table->string('tenant_id', 36)->nullable()->index()->after('hmac_secret');
        });
    }

    public function down(): void
    {
        Schema::table('endpoint_agents', function (Blueprint $table) {
            $table->dropColumn(['hmac_secret', 'tenant_id']);
        });
    }
};
