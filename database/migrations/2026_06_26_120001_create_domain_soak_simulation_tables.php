<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTERPRISE-057: Domain Soak Simulation (endpoint/network/threat-intel)
     *
     * Two append-only tables recording offline soak simulation results.
     * promotion_recommended = false ALWAYS.
     * REAL_SOAK_REQUIRED = true — simulation ≠ real 6h soak.
     * NEVER UPDATE or DELETE rows.
     */
    public function up(): void
    {
        Schema::create('domain_soak_simulations', function (Blueprint $table) {
            $table->id();
            $table->uuid('simulation_id')->unique()->index();
            $table->string('domain', 64)->index();                // endpoint | network | threat-intel
            $table->unsignedInteger('rules_total')->default(0);
            $table->unsignedInteger('rules_simulated')->default(0);
            $table->unsignedInteger('events_generated')->default(0);
            $table->unsignedInteger('structural_matches')->default(0);
            $table->float('structural_match_rate')->nullable();    // structural_matches / events_generated
            $table->float('fp_estimate_rate')->nullable();         // estimated false positive rate
            $table->string('soak_verdict', 32)->default('SIMULATION_ONLY'); // SIMULATION_ONLY always
            $table->boolean('promotion_recommended')->default(false);  // ALWAYS false
            $table->boolean('real_soak_required')->default(true);      // ALWAYS true
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('simulated_at')->useCurrent();
        });

        Schema::create('domain_soak_simulation_gates', function (Blueprint $table) {
            $table->id();
            $table->uuid('simulation_id')->index();
            $table->string('gate_id', 32);
            $table->string('gate_name', 200);
            $table->boolean('passed')->default(false);
            $table->string('status', 16)->default('pending');    // pass | warn | fail
            $table->text('evidence')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->string('tenant_id')->nullable()->index();
            $table->timestampTz('checked_at')->useCurrent();
            $table->index(['simulation_id', 'gate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_soak_simulation_gates');
        Schema::dropIfExists('domain_soak_simulations');
    }
};
