<?php

namespace Tests\Feature;

use App\Support\XdrOperationalEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class XdrEventContractEventSourcingTest extends TestCase
{
    use RefreshDatabase;

    public function test_xdr_contract_files_exist(): void
    {
        foreach ([
            'event-envelope.v1.schema.json',
            'xdr.alerts.v1.schema.json',
            'alerts.created.v1.schema.json',
            'incidents.updated.v1.schema.json',
            'ai.analysis.requests.v1.schema.json',
            'ai.analysis.results.v1.schema.json',
            'ai.analysis.completed.v1.schema.json',
        ] as $file) {
            $path = base_path('docs/contracts/events/'.$file);
            $this->assertFileExists($path);
            $this->assertIsArray(json_decode(file_get_contents($path), true));
        }
    }

    public function test_trace_id_columns_exist_on_pipeline_tables(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('security_alerts', 'trace_id'),
            'security_alerts must have trace_id column (added by pipeline integration migration)'
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('security_incidents', 'trace_id'),
            'security_incidents must have trace_id column (added for end-to-end trace propagation)'
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('xdr_operational_events', 'trace_id'),
            'xdr_operational_events must have trace_id column'
        );
    }

    public function test_xdr_alerts_contract_declares_trace_id_on_alert(): void
    {
        $path = base_path('docs/contracts/events/xdr.alerts.v1.schema.json');
        $schema = json_decode(file_get_contents($path), true);
        $alertProps = $schema['$defs']['alert']['properties'] ?? [];
        $this->assertArrayHasKey('trace_id', $alertProps, 'xdr.alerts alert object must declare trace_id field');
    }

    public function test_operational_event_store_is_replayable_and_idempotent(): void
    {
        $payload = [
            'alert_id' => 'xdr-alert-test',
            'alert_fingerprint' => 'fingerprint-test',
            'alert' => ['alert_type' => 'IDENTITY_RISKY_LOGIN', 'severity' => 'high'],
        ];

        $eventId = XdrOperationalEventStore::append(
            eventType: 'alert.created',
            payload: $payload,
            sourceService: 'alert-writer-service',
            sourceTopic: 'alerts.created',
            aggregateType: 'alert',
            aggregateId: 'xdr-alert-test',
            traceId: 'trace-contract-test',
        );

        XdrOperationalEventStore::append(
            eventType: 'alert.created',
            payload: $payload,
            sourceService: 'alert-writer-service',
            sourceTopic: 'alerts.created',
            aggregateType: 'alert',
            aggregateId: 'xdr-alert-test',
            traceId: 'trace-contract-test',
        );

        $this->assertSame(1, DB::table('xdr_operational_events')->count());
        $row = DB::table('xdr_operational_events')->where('event_id', $eventId)->first();

        $this->assertNotNull($row);
        $this->assertSame('alert.created', $row->event_type);
        $this->assertSame(1, (int) $row->schema_version);
        $this->assertSame('alert', $row->aggregate_type);
        $this->assertSame('xdr-alert-test', $row->aggregate_id);
        $this->assertTrue((bool) $row->replayable);
    }
}
