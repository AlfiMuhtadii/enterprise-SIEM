<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ENT-TENANCY-RESPONSE-EXECUTION: response_executions and its 3
        // append-only child tables (events/rollbacks/simulations — none of
        // these are ever UPDATEd or DELETEd by this migration, only given a
        // new nullable column) had no tenant_id at all. Active response
        // controls highly privileged containment operations (session
        // revocation, host isolation, account disabling); without a tenant
        // boundary a compromised tenant operator could view, simulate,
        // approve, or execute response plans targeting another tenant's
        // resources.
        foreach ([
            'response_executions',
            'response_execution_events',
            'response_execution_rollbacks',
            'response_execution_simulations',
        ] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('tenant_id', 36)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'response_execution_simulations',
            'response_execution_rollbacks',
            'response_execution_events',
            'response_executions',
        ] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('tenant_id');
            });
        }
    }
};
