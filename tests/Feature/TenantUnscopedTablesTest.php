<?php

namespace Tests\Feature;

use App\Services\TenantBoundaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantUnscopedTablesTest extends TestCase
{
    use RefreshDatabase;

    /** TENANT-UNSCOPED-TABLES: investigations table has tenant_id column */
    public function test_investigations_has_tenant_id(): void
    {
        $this->assertTrue(Schema::hasColumn('investigations', 'tenant_id'));
    }

    /** response_plans table has tenant_id column */
    public function test_response_plans_has_tenant_id(): void
    {
        $this->assertTrue(Schema::hasColumn('response_plans', 'tenant_id'));
    }

    /** threat_hunts table has tenant_id column */
    public function test_threat_hunts_has_tenant_id(): void
    {
        $this->assertTrue(Schema::hasColumn('threat_hunts', 'tenant_id'));
    }

    /** entities table has tenant_id column */
    public function test_entities_has_tenant_id(): void
    {
        $this->assertTrue(Schema::hasColumn('entities', 'tenant_id'));
    }

    /** investigations is in ISOLATED_TABLES */
    public function test_investigations_in_isolated_tables(): void
    {
        $this->assertContains('investigations', TenantBoundaryService::ISOLATED_TABLES);
    }

    /** response_plans is in ISOLATED_TABLES */
    public function test_response_plans_in_isolated_tables(): void
    {
        $this->assertContains('response_plans', TenantBoundaryService::ISOLATED_TABLES);
    }

    /** threat_hunts is in ISOLATED_TABLES */
    public function test_threat_hunts_in_isolated_tables(): void
    {
        $this->assertContains('threat_hunts', TenantBoundaryService::ISOLATED_TABLES);
    }

    /** entities is in ISOLATED_TABLES */
    public function test_entities_in_isolated_tables(): void
    {
        $this->assertContains('entities', TenantBoundaryService::ISOLATED_TABLES);
    }

    /** investigations is in MUTABLE_TABLES (workflow table — can be updated) */
    public function test_investigations_in_mutable_tables(): void
    {
        $this->assertContains('investigations', TenantBoundaryService::MUTABLE_TABLES);
    }

    /** response_plans is in MUTABLE_TABLES */
    public function test_response_plans_in_mutable_tables(): void
    {
        $this->assertContains('response_plans', TenantBoundaryService::MUTABLE_TABLES);
    }

    /** entities is in MUTABLE_TABLES (risk scores updated) */
    public function test_entities_in_mutable_tables(): void
    {
        $this->assertContains('entities', TenantBoundaryService::MUTABLE_TABLES);
    }

    /** threat_hunts is in APPEND_ONLY_ISOLATED_TABLES — never UPDATE tenant_id */
    public function test_threat_hunts_in_append_only_isolated(): void
    {
        $this->assertContains('threat_hunts', TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES);
    }

    /** threat_hunts is NOT in MUTABLE_TABLES (append-only) */
    public function test_threat_hunts_not_in_mutable_tables(): void
    {
        $this->assertNotContains('threat_hunts', TenantBoundaryService::MUTABLE_TABLES);
    }

    /** TenantBoundaryService recognizes all four tables as isolated */
    public function test_table_has_isolation_for_all_four_tables(): void
    {
        $svc = app(TenantBoundaryService::class);
        foreach (['investigations', 'response_plans', 'threat_hunts', 'entities'] as $table) {
            $this->assertTrue($svc->tableHasIsolation($table), "tableHasIsolation({$table}) must be true");
        }
    }

    /** ISOLATED_TABLES count increased by 5 (endpoint_agents + 4 workflow tables) */
    public function test_isolated_tables_count_includes_new_tables(): void
    {
        $this->assertGreaterThanOrEqual(22, count(TenantBoundaryService::ISOLATED_TABLES));
    }

    /** UNISOLATED_TABLES no longer contains investigations, response_plans, entities, threat_hunts */
    public function test_workflow_tables_not_in_unisolated(): void
    {
        foreach (['investigations', 'response_plans', 'threat_hunts', 'entities'] as $table) {
            $this->assertNotContains(
                $table,
                TenantBoundaryService::UNISOLATED_TABLES,
                "{$table} must be removed from UNISOLATED_TABLES after adding tenant_id"
            );
        }
    }
}
