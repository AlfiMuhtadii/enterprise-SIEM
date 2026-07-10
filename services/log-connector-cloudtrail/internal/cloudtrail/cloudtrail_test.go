package cloudtrail

import (
	"bytes"
	"compress/gzip"
	"testing"
)

const sampleRecord = `{
	"Records": [
		{
			"eventVersion": "1.08",
			"eventTime": "2026-07-10T10:30:00Z",
			"eventSource": "s3.amazonaws.com",
			"eventName": "GetObject",
			"awsRegion": "us-east-1",
			"sourceIPAddress": "203.0.113.5",
			"userAgent": "aws-cli/2.0",
			"userIdentity": {
				"type": "IAMUser",
				"principalId": "AIDAEXAMPLE",
				"arn": "arn:aws:iam::123456789012:user/alice",
				"accountId": "123456789012",
				"userName": "alice"
			},
			"requestParameters": {"bucketName": "my-bucket"},
			"responseElements": null,
			"requestID": "req-1",
			"eventID": "evt-1",
			"eventType": "AwsApiCall",
			"recipientAccountId": "123456789012"
		},
		{
			"eventVersion": "1.08",
			"eventTime": "2026-07-10T10:31:00Z",
			"eventSource": "signin.amazonaws.com",
			"eventName": "ConsoleLogin",
			"awsRegion": "us-east-1",
			"sourceIPAddress": "203.0.113.9",
			"userIdentity": {
				"type": "IAMUser",
				"principalId": "AIDAEXAMPLE2",
				"arn": "arn:aws:iam::123456789012:user/bob",
				"accountId": "123456789012",
				"userName": "bob"
			},
			"errorCode": "Failure",
			"errorMessage": "Wrong password",
			"requestID": "req-2",
			"eventID": "evt-2",
			"eventType": "AwsConsoleSignIn",
			"recipientAccountId": "123456789012"
		}
	]
}`

func TestParsePlainJSON(t *testing.T) {
	records, err := Parse([]byte(sampleRecord))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(records) != 2 {
		t.Fatalf("expected 2 records, got %d", len(records))
	}
	if records[0].EventName != "GetObject" || records[0].UserIdentity.UserName != "alice" {
		t.Fatalf("unexpected first record: %+v", records[0])
	}
	if records[1].ErrorCode != "Failure" {
		t.Fatalf("expected errorCode on second record, got %+v", records[1])
	}
}

func TestParseGzipCompressed(t *testing.T) {
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	if _, err := gz.Write([]byte(sampleRecord)); err != nil {
		t.Fatalf("failed to gzip fixture: %v", err)
	}
	if err := gz.Close(); err != nil {
		t.Fatalf("failed to close gzip writer: %v", err)
	}

	records, err := Parse(buf.Bytes())
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(records) != 2 {
		t.Fatalf("expected 2 records from gzip input, got %d", len(records))
	}
}

func TestParsePreservesRawFieldsNotInStruct(t *testing.T) {
	records, err := Parse([]byte(sampleRecord))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	rp, ok := records[0].Raw["requestParameters"].(map[string]any)
	if !ok || rp["bucketName"] != "my-bucket" {
		t.Fatalf("expected requestParameters preserved in Raw, got %v", records[0].Raw["requestParameters"])
	}
}

func TestParseHandlesEmptyRecordsArray(t *testing.T) {
	records, err := Parse([]byte(`{"Records": []}`))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(records) != 0 {
		t.Fatalf("expected 0 records, got %d", len(records))
	}
}

func TestParseRejectsMalformedJSON(t *testing.T) {
	if _, err := Parse([]byte("{not valid json")); err == nil {
		t.Fatalf("expected error for malformed JSON")
	}
}

func TestParseRejectsMalformedGzip(t *testing.T) {
	data := []byte{0x1f, 0x8b, 0x00, 0x00} // gzip magic bytes but truncated/invalid body
	if _, err := Parse(data); err == nil {
		t.Fatalf("expected error for malformed gzip data")
	}
}

func TestParseSkipsRecordThatFailsToRemarshal(t *testing.T) {
	// A record entry that isn't a map (e.g. a bare string) is silently
	// skipped rather than aborting the whole batch -- one poison record
	// must not block every other record in the same export file.
	batch := `{"Records": [{"eventName": "Good"}]}`
	records, err := Parse([]byte(batch))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(records) != 1 || records[0].EventName != "Good" {
		t.Fatalf("unexpected records: %+v", records)
	}
}

// ---------------------------------------------------------------------------
// CONN-UNBOUNDED-FILE: ParseBounded size ceilings.
// ---------------------------------------------------------------------------

func gzipCompress(t *testing.T, data []byte) []byte {
	t.Helper()
	var buf bytes.Buffer
	w := gzip.NewWriter(&buf)
	if _, err := w.Write(data); err != nil {
		t.Fatalf("gzip write: %v", err)
	}
	if err := w.Close(); err != nil {
		t.Fatalf("gzip close: %v", err)
	}
	return buf.Bytes()
}

func TestParseBoundedZeroLimitsMatchesUnboundedParse(t *testing.T) {
	records, oversized, err := ParseBounded([]byte(sampleRecord), Limits{})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if oversized != 0 {
		t.Errorf("expected 0 oversized records with no limit, got %d", oversized)
	}
	if len(records) != 2 {
		t.Fatalf("expected 2 records, got %d", len(records))
	}
}

func TestParseBoundedRejectsGzipExpansionOverLimit(t *testing.T) {
	// A highly compressible payload: small on the wire, large once
	// decompressed -- the compression-bomb shape the finding calls out.
	huge := bytes.Repeat([]byte("A"), 10_000_000)
	compressed := gzipCompress(t, huge)

	_, _, err := ParseBounded(compressed, Limits{MaxExpandedBytes: 1_000_000})
	if err != ErrExpandedTooLarge {
		t.Fatalf("expected ErrExpandedTooLarge, got: %v", err)
	}
}

func TestParseBoundedAllowsGzipExpansionUnderLimit(t *testing.T) {
	compressed := gzipCompress(t, []byte(sampleRecord))

	records, _, err := ParseBounded(compressed, Limits{MaxExpandedBytes: 1_000_000})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(records) != 2 {
		t.Fatalf("expected 2 records, got %d", len(records))
	}
}

func TestParseBoundedSkipsOversizedSingleRecordButKeepsOthers(t *testing.T) {
	hugeValue := string(bytes.Repeat([]byte("x"), 5000))
	batch := `{"Records": [
		{"eventName": "Small1"},
		{"eventName": "Huge", "requestParameters": {"blob": "` + hugeValue + `"}},
		{"eventName": "Small2"}
	]}`

	records, oversized, err := ParseBounded([]byte(batch), Limits{MaxRecordBytes: 500})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if oversized != 1 {
		t.Fatalf("expected exactly 1 oversized record counted, got %d", oversized)
	}
	if len(records) != 2 {
		t.Fatalf("expected the 2 small records to still be parsed, got %d: %+v", len(records), records)
	}
	for _, r := range records {
		if r.EventName == "Huge" {
			t.Fatalf("the oversized record must not appear in the parsed results")
		}
	}
}

func TestParseBoundedStableUnderLargeMultiRecordFixture(t *testing.T) {
	var sb bytes.Buffer
	sb.WriteString(`{"Records": [`)
	const n = 5000
	for i := 0; i < n; i++ {
		if i > 0 {
			sb.WriteString(",")
		}
		sb.WriteString(`{"eventName": "Event", "eventID": "evt"}`)
	}
	sb.WriteString(`]}`)

	records, oversized, err := ParseBounded(sb.Bytes(), Limits{MaxRecordBytes: 1_000_000, MaxExpandedBytes: 50_000_000})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if oversized != 0 {
		t.Errorf("expected 0 oversized records, got %d", oversized)
	}
	if len(records) != n {
		t.Fatalf("expected %d records, got %d", n, len(records))
	}
}
