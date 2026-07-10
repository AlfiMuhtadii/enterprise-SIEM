package guardduty

import (
	"bytes"
	"compress/gzip"
	"testing"
)

const sampleNDJSON = `{"SchemaVersion":"2.0","AccountId":"123456789012","Region":"us-east-1","Id":"finding-1","Type":"UnauthorizedAccess:EC2/SSHBruteForce","Severity":5.0,"CreatedAt":"2026-07-10T10:00:00.000Z","UpdatedAt":"2026-07-10T10:00:00.000Z","Title":"SSH brute force","Description":"desc","Resource":{"ResourceType":"Instance"},"Service":{"Action":{"ActionType":"NETWORK_CONNECTION","NetworkConnectionAction":{"RemoteIpDetails":{"IpAddressV4":"203.0.113.5"}}},"Count":10}}
{"SchemaVersion":"2.0","AccountId":"123456789012","Region":"us-east-1","Id":"finding-2","Type":"Recon:EC2/PortProbeUnprotectedPort","Severity":2.0,"CreatedAt":"2026-07-10T10:01:00.000Z","UpdatedAt":"2026-07-10T10:01:00.000Z","Title":"Port probe","Description":"desc2","Resource":{"ResourceType":"Instance"},"Service":{"Action":{"ActionType":"PORT_PROBE","PortProbeAction":{"PortProbeDetails":[{"RemoteIpDetails":{"IpAddressV4":"203.0.113.9"}}]}}}}`

func TestParsePlainNDJSON(t *testing.T) {
	findings, err := Parse([]byte(sampleNDJSON))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(findings) != 2 {
		t.Fatalf("expected 2 findings, got %d", len(findings))
	}
	if findings[0].Type != "UnauthorizedAccess:EC2/SSHBruteForce" || findings[0].AccountID != "123456789012" {
		t.Fatalf("unexpected first finding: %+v", findings[0])
	}
	if findings[1].Type != "Recon:EC2/PortProbeUnprotectedPort" {
		t.Fatalf("unexpected second finding: %+v", findings[1])
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

	findings, err := Parse(buf.Bytes())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(findings) != 2 {
		t.Fatalf("expected 2 findings from gzip input, got %d", len(findings))
	}
}

func TestParsePreservesRawFieldsNotInStruct(t *testing.T) {
	findings, err := Parse([]byte(sampleNDJSON))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	svc, ok := findings[0].Raw["Service"].(map[string]any)
	if !ok {
		t.Fatalf("expected Service preserved in Raw, got %v", findings[0].Raw["Service"])
	}
	if _, hasAction := svc["Action"]; !hasAction {
		t.Fatalf("expected Action nested in raw Service, got %v", svc)
	}
}

func TestParseSkipsBlankLines(t *testing.T) {
	data := "{" + `"Id":"a","Type":"T"` + "}\n\n\n" + "{" + `"Id":"b","Type":"T2"` + "}\n"
	findings, err := Parse([]byte(data))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(findings) != 2 {
		t.Fatalf("expected 2 findings ignoring blank lines, got %d", len(findings))
	}
}

func TestParseSkipsPoisonLineWithoutAbortingFile(t *testing.T) {
	data := `{"Id":"a","Type":"Good"}` + "\n" + "not valid json at all" + "\n" + `{"Id":"b","Type":"AlsoGood"}`
	findings, err := Parse([]byte(data))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(findings) != 2 {
		t.Fatalf("expected 2 valid findings with poison line skipped, got %d", len(findings))
	}
}

func TestParseHandlesEmptyInput(t *testing.T) {
	findings, err := Parse([]byte(""))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(findings) != 0 {
		t.Fatalf("expected 0 findings, got %d", len(findings))
	}
}

func TestParseRejectsMalformedGzip(t *testing.T) {
	data := []byte{0x1f, 0x8b, 0x00, 0x00}
	if _, err := Parse(data); err == nil {
		t.Fatalf("expected error for malformed gzip data")
	}
}

func TestRemoteIPAddressExtractsFromNetworkConnectionAction(t *testing.T) {
	findings, _ := Parse([]byte(sampleNDJSON))
	if ip := findings[0].RemoteIPAddress(); ip != "203.0.113.5" {
		t.Fatalf("expected 203.0.113.5, got %q", ip)
	}
}

func TestRemoteIPAddressExtractsFromPortProbeAction(t *testing.T) {
	findings, _ := Parse([]byte(sampleNDJSON))
	if ip := findings[1].RemoteIPAddress(); ip != "203.0.113.9" {
		t.Fatalf("expected 203.0.113.9, got %q", ip)
	}
}

func TestRemoteIPAddressReturnsEmptyWhenNoActionMatches(t *testing.T) {
	f := Finding{Service: map[string]any{"Action": map[string]any{"ActionType": "UNKNOWN"}}}
	if ip := f.RemoteIPAddress(); ip != "" {
		t.Fatalf("expected empty string, got %q", ip)
	}
}

func TestRemoteIPAddressReturnsEmptyWhenNoServiceAction(t *testing.T) {
	f := Finding{Service: map[string]any{}}
	if ip := f.RemoteIPAddress(); ip != "" {
		t.Fatalf("expected empty string, got %q", ip)
	}
}
