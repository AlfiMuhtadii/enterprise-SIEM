package o365

import (
	"bytes"
	"testing"
)

const sampleContentBlob = `[
	{"Id":"rec-1","CreationTime":"2026-07-11T10:00:00","Operation":"UserLoggedIn","Workload":"AzureActiveDirectory","UserId":"alice@contoso.com","ClientIP":"203.0.113.5","ResultStatus":"Success"},
	{"Id":"rec-2","CreationTime":"2026-07-11T10:01:00","Operation":"FileAccessed","Workload":"SharePoint","UserId":"bob@contoso.com","ClientIP":"203.0.113.9","ResultStatus":"Success"}
]`

func TestParsePlainJSONArray(t *testing.T) {
	records, err := Parse([]byte(sampleContentBlob))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(records) != 2 {
		t.Fatalf("expected 2 records, got %d", len(records))
	}
	if records[0].ID != "rec-1" || records[0].Operation != "UserLoggedIn" || records[0].UserID != "alice@contoso.com" {
		t.Fatalf("unexpected record[0]: %+v", records[0])
	}
	if records[1].Workload != "SharePoint" {
		t.Fatalf("unexpected record[1]: %+v", records[1])
	}
}

func TestParsePreservesRawFieldsNotInStruct(t *testing.T) {
	blob := `[{"Id":"rec-1","Operation":"UserLoggedIn","ExtraWorkloadSpecificField":"xyz"}]`
	records, err := Parse([]byte(blob))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if records[0].Raw["ExtraWorkloadSpecificField"] != "xyz" {
		t.Fatalf("expected unmodeled field preserved in Raw, got %v", records[0].Raw)
	}
}

func TestParseHandlesEmptyArray(t *testing.T) {
	records, err := Parse([]byte(`[]`))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(records) != 0 {
		t.Fatalf("expected 0 records, got %d", len(records))
	}
}

func TestParseRejectsMalformedJSON(t *testing.T) {
	if _, err := Parse([]byte("{not valid")); err == nil {
		t.Fatal("expected error for malformed JSON")
	}
}

func TestParseRejectsTopLevelObjectNotArray(t *testing.T) {
	// The content blob is a bare array, unlike CloudTrail's {"Records":[...]}
	// envelope — a top-level object must be rejected, not silently treated
	// as zero records.
	if _, err := Parse([]byte(`{"Records":[]}`)); err == nil {
		t.Fatal("expected error when the top-level value is not a JSON array")
	}
}

func TestParseSkipsRecordThatFailsToRemarshal(t *testing.T) {
	blob := `[{"Id":"rec-1","Operation":"Good"}]`
	records, err := Parse([]byte(blob))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(records) != 1 || records[0].Operation != "Good" {
		t.Fatalf("unexpected records: %+v", records)
	}
}

// ---------------------------------------------------------------------------
// CONN-UNBOUNDED-FILE: ParseBounded per-record size ceiling.
// ---------------------------------------------------------------------------

func TestParseBoundedZeroLimitsMatchesUnboundedParse(t *testing.T) {
	records, oversized, err := ParseBounded([]byte(sampleContentBlob), Limits{})
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

func TestParseBoundedSkipsOversizedRecordButKeepsOthers(t *testing.T) {
	hugeValue := string(bytes.Repeat([]byte("x"), 5000))
	blob := `[
		{"Id":"rec-small1","Operation":"A"},
		{"Id":"rec-huge","Operation":"B","Extra":"` + hugeValue + `"},
		{"Id":"rec-small2","Operation":"C"}
	]`

	records, oversized, err := ParseBounded([]byte(blob), Limits{MaxRecordBytes: 500})
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
		if r.ID == "rec-huge" {
			t.Fatal("the oversized record must not appear in the parsed results")
		}
	}
}

func TestParseBoundedStableUnderLargeMultiRecordFixture(t *testing.T) {
	var sb bytes.Buffer
	sb.WriteString("[")
	const n = 5000
	for i := 0; i < n; i++ {
		if i > 0 {
			sb.WriteString(",")
		}
		sb.WriteString(`{"Id":"rec","Operation":"Event"}`)
	}
	sb.WriteString("]")

	records, oversized, err := ParseBounded(sb.Bytes(), Limits{MaxRecordBytes: 1_000_000})
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
