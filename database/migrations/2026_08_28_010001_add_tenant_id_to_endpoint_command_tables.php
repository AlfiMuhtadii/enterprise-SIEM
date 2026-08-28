<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('endpoint_response_commands', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('agent_id');
            $table->index(['tenant_id', 'status'], 'endpoint_response_commands_tenant_status_idx');
        });

        Schema::table('agent_commands', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('agent_id');
            $table->index(['tenant_id', 'queued_at'], 'agent_commands_tenant_queued_idx');
        });

        DB::table('endpoint_response_commands')
            ->whereNull('tenant_id')
            ->chunkById(500, function ($commands): void {
                foreach ($commands as $command) {
                    $tenantId = DB::table('endpoint_agents')
                        ->where('id', $command->agent_id)
                        ->value('tenant_id');

                    if ($tenantId !== null) {
                        DB::table('endpoint_response_commands')
                            ->where('id', $command->id)
                            ->update(['tenant_id' => $tenantId]);
                    }
                }
            });

        DB::table('agent_commands')
            ->whereNull('tenant_id')
            ->chunkById(500, function ($commands): void {
                foreach ($commands as $command) {
                    $tenantId = DB::table('endpoint_agents')
                        ->where('agent_id', $command->agent_id)
                        ->value('tenant_id');

                    if ($tenantId !== null) {
                        DB::table('agent_commands')
                            ->where('id', $command->id)
                            ->update(['tenant_id' => $tenantId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('agent_commands', function (Blueprint $table) {
            $table->dropIndex('agent_commands_tenant_queued_idx');
            $table->dropColumn('tenant_id');
        });

        Schema::table('endpoint_response_commands', function (Blueprint $table) {
            $table->dropIndex('endpoint_response_commands_tenant_status_idx');
            $table->dropColumn('tenant_id');
        });
    }
};
