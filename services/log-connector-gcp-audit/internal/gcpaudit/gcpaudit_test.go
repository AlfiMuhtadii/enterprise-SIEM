package gcpaudit

import (
	"bytes"
	"compress/gzip"
	"testing"
)

const sampleNDJSON = `{"insertId":"abc123","timestamp":"2026-07-10T10:00:00.123456Z","severity":"NOTICE","logName":"projects/my-project/logs/cloudaudit.googleapis.com%2Fdata_access","resource":{"type":"gcs_bucket","labels":{"project_id":"my-project","bucket_name":"my-bucket"}},"protoPayload":{"@type":"type.googleapis.com/google.cloud.audit.AuditLog","status":{},"authenticationInfo":{"principalEmail":"alice@example.com"},"requestMetadata":{"callerIp":"203.0.113.5"},"serviceName":"storage.googleapis.com","methodName":"storage.objects.get"}}
{"insertId":"def456","timestamp":"2026-07-10T10:01:00.000000Z","severity":"ERROR","logName":"projects/my-project/logs/cloudaudit.googleapis.com%2Factivity","resource":{"type":"gce_instance","labels":{"project_id":"my-project"}},"protoPayload":{"status":{"code":7,"message":"PERMISSION_DENIED"},"authenticationInfo":{"principalEmail":"bob@example.com"},"requestMetadata":{"callerIp":"203.0.113.9"},"serviceName":"compute.googleapis.com","methodName":"v1.compute.instances.delete"}}`

func TestParsePlainNDJSON(t *testing.T) {
	entries, err := Parse([]byte(sampleNDJSON))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(entries) != 2 {
		t.Fatalf("expected 2 entries, got %d", len(entries))
	}
	if entries[0].MethodName() != "storage.objects.get" {
		t.Fatalf("unexpected first entry method: %+v", entries[0])
	}
	if entries[1].MethodName() != "v1.compute.instances.delete" {
		t.Fatalf("unexpected second entry method: %+v", entries[1])
	}
}

func TestParseGzipCompressed(t *testing.T) {
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	if _, err := gz.Write([]byte(sampleNDJSON)); err != nil {
		t.Fatalf("failed to gzip fixture: %v", err)
	}
	if err := gz.Close(); err != nil {
		t.Fatalf("failed to close gzip writer: %v", err)
	}

	entries, err := Parse(buf.Bytes())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(entries) != 2 {
		t.Fatalf("expected 2 entries from gzip input, got %d", len(entries))
	}
}

func TestParsePreservesRawFieldsNotInStruct(t *testing.T) {
	entries, err := Parse([]byte(sampleNDJSON))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	payload, ok := entries[0].Raw["protoPayload"].(map[string]any)
	if !ok {
		t.Fatalf("expected protoPayload preserved in Raw, got %v", entries[0].Raw["protoPayload"])
	}
	if payload["@type"] != "type.googleapis.com/google.cloud.audit.AuditLog" {
		t.Fatalf("expected @type preserved verbatim, got %v", payload["@type"])
	}
}

func TestParseSkipsPoisonLineWithoutAbortingFile(t *testing.T) {
	data := `{"insertId":"a","protoPayload":{"methodName":"m1"}}` + "\n" + "not valid json" + "\n" + `{"insertId":"b","protoPayload":{"methodName":"m2"}}`
	entries, err := Parse([]byte(data))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(entries) != 2 {
		t.Fatalf("expected 2 valid entries with poison line skipped, got %d", len(entries))
	}
}

func TestParseHandlesEmptyInput(t *testing.T) {
	entries, err := Parse([]byte(""))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(entries) != 0 {
		t.Fatalf("expected 0 entries, got %d", len(entries))
	}
}

func TestParseRejectsMalformedGzip(t *testing.T) {
	data := []byte{0x1f, 0x8b, 0x00, 0x00}
	if _, err := Parse(data); err == nil {
		t.Fatalf("expected error for malformed gzip data")
	}
}

func TestPrincipalEmailAndCallerIP(t *testing.T) {
	entries, _ := Parse([]byte(sampleNDJSON))
	if entries[0].PrincipalEmail() != "alice@example.com" {
		t.Fatalf("expected alice@example.com, got %q", entries[0].PrincipalEmail())
	}
	if entries[0].CallerIP() != "203.0.113.5" {
		t.Fatalf("expected 203.0.113.5, got %q", entries[0].CallerIP())
	}
}

func TestProjectID(t *testing.T) {
	entries, _ := Parse([]byte(sampleNDJSON))
	if entries[0].ProjectID() != "my-project" {
		t.Fatalf("expected my-project, got %q", entries[0].ProjectID())
	}
}

func TestServiceName(t *testing.T) {
	entries, _ := Parse([]byte(sampleNDJSON))
	if entries[0].ServiceName() != "storage.googleapis.com" {
		t.Fatalf("expected storage.googleapis.com, got %q", entries[0].ServiceName())
	}
}

func TestHasErrorStatusFalseForEmptyStatus(t *testing.T) {
	entries, _ := Parse([]byte(sampleNDJSON))
	if entries[0].HasErrorStatus() {
		t.Fatalf("expected no error status for empty status object")
	}
}

func TestHasErrorStatusTrueForPopulatedStatus(t *testing.T) {
	entries, _ := Parse([]byte(sampleNDJSON))
	if !entries[1].HasErrorStatus() {
		t.Fatalf("expected error status true for populated status with code+message")
	}
}

func TestProjectIDEmptyWhenNoLabels(t *testing.T) {
	e := LogEntry{Resource: Resource{}}
	if e.ProjectID() != "" {
		t.Fatalf("expected empty project id, got %q", e.ProjectID())
	}
}

func TestHasErrorStatusFalseWhenNoStatusField(t *testing.T) {
	e := LogEntry{ProtoPayload: map[string]any{}}
	if e.HasErrorStatus() {
		t.Fatalf("expected false when no status field present")
	}
}
