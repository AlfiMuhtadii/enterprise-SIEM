package shadowrules

import (
	"reflect"
	"strconv"
	"testing"
)

func TestNetworkAggregatesRetainOnlyContributingEventsAndStableHostIdentity(t *testing.T) {
	tests := []struct {
		name, kind string
		minimum    int
		field      string
		value      any
		evaluate   func([]map[string]any) []EndpointAlert
	}{
		{"dns-txt", "dns", 2, "query_type", "TXT", ruleNetworkDnsTxtQueryPattern},
		{"nxdomain", "dns", 5, "response_code", "NXDOMAIN", ruleNetworkRepeatedNxdomain},
		{"proxy-egress", "proxy", 2, "bytes_out", int64(26 * 1024 * 1024), ruleNetworkHighVolumeProxyEgress},
		{"firewall-denied", "firewall", 5, "action", "deny", ruleNetworkRepeatedDeniedOutbound},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			var inputs []map[string]any
			var ids []string
			for i := 0; i < tt.minimum; i++ {
				ids = append(ids, "source-"+strconv.Itoa(i))
			}
			for _, host := range []string{"host-a", "host-b"} {
				for i, id := range ids {
					inputs = append(inputs, map[string]any{
						"telemetry_type": tt.kind, "host_id": host, tt.field: tt.value,
						"normalized_event_id": id, "trace_id": "trace-" + strconv.Itoa(i%2),
					})
				}
			}
			inputs = append(inputs, inputs[0], map[string]any{
				"telemetry_type": "unrelated", "host_id": "host-a", "normalized_event_id": "unrelated-source",
			})
			alerts := tt.evaluate(inputs)
			if len(alerts) != 2 || alerts[0].Host != "host-a" || alerts[1].Host != "host-b" ||
				alerts[0].AlertID == alerts[1].AlertID {
				t.Fatalf("host aggregates collided: %+v", alerts)
			}
			for _, alert := range alerts {
				if !reflect.DeepEqual(alert.EvidenceIDs, ids) ||
					!reflect.DeepEqual(alert.Evidence["source_trace_ids"], []string{"trace-0", "trace-1"}) ||
					alert.Evidence["evidence_lineage_complete"] != true || alert.TraceID != "" {
					t.Fatalf("unexpected source lineage: %+v", alert)
				}
			}
			for i, j := 0, len(inputs)-1; i < j; i, j = i+1, j-1 {
				inputs[i], inputs[j] = inputs[j], inputs[i]
			}
			if replay := tt.evaluate(inputs); !reflect.DeepEqual(replay, alerts) {
				t.Fatalf("reordered identical evidence changed alert identity or contents: %+v", replay)
			}
		})
	}
}

func TestNetworkAggregatesDoNotCountReplayTowardThreshold(t *testing.T) {
	event := map[string]any{
		"telemetry_type": "dns", "host_id": "host-a", "query_type": "TXT",
		"normalized_event_id": "source-1", "trace_id": "trace",
	}
	if alerts := ruleNetworkDnsTxtQueryPattern([]map[string]any{event, event, event}); len(alerts) != 0 {
		t.Fatalf("duplicate source events satisfied rule threshold: %+v", alerts)
	}
}

func TestNetworkAggregateRetainsMissingOrAmbiguousLineageWithoutInventingTrace(t *testing.T) {
	a := map[string]any{
		"telemetry_type": "dns", "host_id": "host-a", "query_type": "TXT",
		"raw_event_id": "source-1", "trace_id": "trace",
	}
	b := map[string]any{"telemetry_type": "dns", "host_id": "host-a", "query_type": "TXT"}
	alerts := ruleNetworkDnsTxtQueryPattern([]map[string]any{a, b})
	if len(alerts) != 1 || alerts[0].Evidence["evidence_lineage_complete"] != false ||
		alerts[0].TraceID != "" || !reflect.DeepEqual(alerts[0].EvidenceIDs, []string{"source-1"}) {
		t.Fatalf("unexpected incomplete source lineage: %+v", alerts)
	}
	b["normalized_event_id"] = "source-2"
	b["trace_id"] = "trace"
	alerts = ruleNetworkDnsTxtQueryPattern([]map[string]any{a, b})
	if len(alerts) != 1 || alerts[0].Evidence["evidence_lineage_complete"] != true || alerts[0].TraceID != "trace" {
		t.Fatalf("shared trace was not preserved: %+v", alerts)
	}
	a2 := map[string]any{
		"telemetry_type": "dns", "host_id": "host-a", "query_type": "TXT",
		"raw_event_id": "source-1", "trace_id": "different-trace",
	}
	alerts = ruleNetworkDnsTxtQueryPattern([]map[string]any{a, b, a2})
	if len(alerts) != 1 || alerts[0].Evidence["evidence_lineage_complete"] != false || alerts[0].TraceID != "" {
		t.Fatalf("conflicting trace for source ID was treated as complete: %+v", alerts)
	}
}

func TestNetworkAggregateBoundsLineageAndKeepsFullContributorCount(t *testing.T) {
	var events []map[string]any
	for i := 0; i <= maxNetworkLineageRefs; i++ {
		id := strconv.Itoa(i)
		events = append(events, map[string]any{
			"host_id": "host-a", "telemetry_type": "dns", "query_type": "TXT",
			"normalized_event_id": id, "trace_id": "trace-" + id,
		})
	}
	alerts := ruleNetworkDnsTxtQueryPattern(events)
	if len(alerts) != 1 || len(alerts[0].EvidenceIDs) != maxNetworkLineageRefs ||
		len(alerts[0].Evidence["source_trace_ids"].([]string)) != maxNetworkLineageRefs ||
		alerts[0].Evidence["source_event_count"] != len(events) ||
		alerts[0].Evidence["evidence_lineage_truncated"] != true ||
		alerts[0].Evidence["evidence_lineage_complete"] != false {
		t.Fatalf("large aggregate lineage was not correctly limited")
	}
}
