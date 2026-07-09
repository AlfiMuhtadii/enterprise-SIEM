package registry

import (
	"os"
	"path/filepath"
	"testing"
)

func TestLoadWithEmptyPathReturnsEmptyRegistry(t *testing.T) {
	reg, err := Load("")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(reg.Sources) != 0 {
		t.Fatalf("expected empty registry, got %d sources", len(reg.Sources))
	}
	if reg.Match("anything at all") != nil {
		t.Fatalf("expected empty registry to never match")
	}
}

func TestNilRegistryMatchReturnsNil(t *testing.T) {
	var reg *Registry
	if reg.Match("APPFW: src=1.2.3.4") != nil {
		t.Fatalf("expected nil registry to never match")
	}
}

func TestLoadReadsValidJSONConfig(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "parsers.json")
	config := `{
		"sources": [
			{
				"name": "generic_fw",
				"marker": "APPFW:",
				"telemetry_type": "syslog_generic_kv",
				"event_type_field": "act",
				"field_map": {
					"source_ip": "src",
					"destination_ip": "dst",
					"action": "act",
					"user": "suser"
				}
			}
		]
	}`
	if err := os.WriteFile(path, []byte(config), 0o644); err != nil {
		t.Fatalf("failed to write test config: %v", err)
	}

	reg, err := Load(path)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(reg.Sources) != 1 || reg.Sources[0].Name != "generic_fw" {
		t.Fatalf("unexpected registry contents: %+v", reg)
	}
}

func TestLoadReturnsErrorForMissingFile(t *testing.T) {
	if _, err := Load(filepath.Join(t.TempDir(), "does-not-exist.json")); err == nil {
		t.Fatalf("expected error for missing file")
	}
}

func TestLoadReturnsErrorForInvalidJSON(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "bad.json")
	if err := os.WriteFile(path, []byte("{not valid json"), 0o644); err != nil {
		t.Fatalf("failed to write test config: %v", err)
	}
	if _, err := Load(path); err == nil {
		t.Fatalf("expected error for invalid JSON")
	}
}

func TestMatchFindsFirstDefinitionByMarker(t *testing.T) {
	reg := &Registry{Sources: []SourceDefinition{
		{Name: "a", Marker: "MARKER_A:"},
		{Name: "b", Marker: "MARKER_B:"},
	}}
	def := reg.Match("prefix MARKER_B: src=1.2.3.4")
	if def == nil || def.Name != "b" {
		t.Fatalf("expected match on definition b, got %+v", def)
	}
}

func TestMatchReturnsNilWhenNoMarkerFound(t *testing.T) {
	reg := &Registry{Sources: []SourceDefinition{{Name: "a", Marker: "MARKER_A:"}}}
	if reg.Match("no marker here at all") != nil {
		t.Fatalf("expected no match")
	}
}

func TestParsePromotesMappedFieldsAndPreservesExtension(t *testing.T) {
	def := SourceDefinition{
		Name:           "generic_fw",
		Marker:         "APPFW:",
		TelemetryType:  "syslog_generic_kv",
		EventTypeField: "act",
		FieldMap: map[string]string{
			"source_ip":      "src",
			"destination_ip": "dst",
			"action":         "act",
			"user":           "suser",
		},
	}
	line := "APPFW: src=10.0.0.5 dst=203.0.113.9 act=blocked suser=alice extra=ignored_but_preserved"
	msg := def.Parse(line)

	if msg.SourceName != "generic_fw" || msg.TelemetryType != "syslog_generic_kv" {
		t.Fatalf("unexpected message identity: %+v", msg)
	}
	if msg.EventType != "blocked" {
		t.Fatalf("expected event type from act field, got %q", msg.EventType)
	}
	if msg.Fields["source_ip"] != "10.0.0.5" || msg.Fields["destination_ip"] != "203.0.113.9" {
		t.Fatalf("unexpected promoted fields: %+v", msg.Fields)
	}
	if msg.Fields["action"] != "blocked" || msg.Fields["user"] != "alice" {
		t.Fatalf("unexpected promoted fields: %+v", msg.Fields)
	}
	if msg.Extension["extra"] != "ignored_but_preserved" {
		t.Fatalf("expected unmapped key preserved verbatim in Extension, got %+v", msg.Extension)
	}
}

func TestParseHandlesLineWithoutMarkerPrefix(t *testing.T) {
	def := SourceDefinition{Name: "x", Marker: "NOPE:", FieldMap: map[string]string{"user": "suser"}}
	msg := def.Parse("suser=bob act=allow")
	if msg.Fields["user"] != "bob" {
		t.Fatalf("expected parse to still extract kv pairs when marker absent from line, got %+v", msg.Fields)
	}
}

func TestParseSkipsUnmappedOrEmptySourceKeys(t *testing.T) {
	def := SourceDefinition{
		Name:     "x",
		Marker:   "X:",
		FieldMap: map[string]string{"source_ip": "src", "user": "missing_key"},
	}
	msg := def.Parse("X: src=1.2.3.4")
	if msg.Fields["source_ip"] != "1.2.3.4" {
		t.Fatalf("expected src promoted, got %+v", msg.Fields)
	}
	if _, ok := msg.Fields["user"]; ok {
		t.Fatalf("expected no user field when source key absent, got %+v", msg.Fields)
	}
}

func TestParseWithoutEventTypeFieldLeavesEventTypeEmpty(t *testing.T) {
	def := SourceDefinition{Name: "x", Marker: "X:", FieldMap: map[string]string{}}
	msg := def.Parse("X: a=1")
	if msg.EventType != "" {
		t.Fatalf("expected empty event type, got %q", msg.EventType)
	}
}
