<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('endpoint_agent_heartbeats')
            || Schema::hasColumn('endpoint_agent_heartbeats', 'tenant_id')) {
            return;
        }

        Schema::table('endpoint_agent_heartbeats', function (Blueprint $table): void {
            // Existing heartbeat rows remain untouched because this table is append-only.
            $table->string('tenant_id', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('endpoint_agent_heartbeats')
            || ! Schema::hasColumn('endpoint_agent_heartbeats', 'tenant_id')) {
            return;
        }

        Schema::table('endpoint_agent_heartbeats', function (Blueprint $table): void {
            $table->dropIndex('endpoint_agent_heartbeats_tenant_id_index');
            $table->dropColumn('tenant_id');
        });
    }
};
