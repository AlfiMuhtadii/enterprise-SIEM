package o365

import "encoding/json"

// AuditRecord is one parsed Management Activity audit record. Field shape
// varies significantly by Workload/RecordType (AzureActiveDirectory,
// Exchange, SharePoint, ...) — only the fields common across all workloads
// are promoted to named struct fields; Raw preserves the entire original
// record so no detail is lost regardless of workload.
type AuditRecord struct {
	ID             string         `json:"Id"`
	CreationTime   string         `json:"CreationTime"`
	Operation      string         `json:"Operation"`
	OrganizationID string         `json:"OrganizationId"`
	Workload       string         `json:"Workload"`
	UserID         string         `json:"UserId"`
	ClientIP       string         `json:"ClientIP"`
	ResultStatus   string         `json:"ResultStatus"`
	Raw            map[string]any `json:"-"`
}

// Limits bounds ParseBounded's resource usage. Zero value means "no limit",
// matching Parse's original unbounded behavior. Unlike the file connectors'
// parsers, there is no MaxExpandedBytes here — a content blob is a plain
// HTTPS JSON response (transparently gzip-decoded by net/http's transport
// when applicable), not a gzip-compressed export file this package handles
// itself; the response-body-size bound lives in Client.FetchContent
// instead.
type Limits struct {
	// MaxRecordBytes caps the re-marshaled JSON size of a single audit
	// record. A record over this limit is counted in the returned
	// oversizedRecords count and skipped — never unmarshaled into the
	// typed struct. 0 = unlimited.
	MaxRecordBytes int64
}

// Parse decodes a content blob: a plain top-level JSON array of audit
// record objects (NOT wrapped in an envelope key, unlike CloudTrail's
// {"Records": [...]}). A record that fails to decode is skipped rather
// than aborting the whole blob. Equivalent to ParseBounded with no limits.
func Parse(data []byte) ([]AuditRecord, error) {
	records, _, err := ParseBounded(data, Limits{})
	return records, err
}

// ParseBounded is Parse with a per-record size ceiling: any array element
// whose re-marshaled size exceeds limits.MaxRecordBytes is skipped and
// counted in the returned oversizedRecords rather than unmarshaled — the
// caller is expected to surface that count (metric/log), not treat it as a
// silent drop.
func ParseBounded(data []byte, limits Limits) (records []AuditRecord, oversizedRecords int, err error) {
	var raws []map[string]any
	if err := json.Unmarshal(data, &raws); err != nil {
		return nil, 0, err
	}

	records = make([]AuditRecord, 0, len(raws))
	for _, raw := range raws {
		encoded, err := json.Marshal(raw)
		if err != nil {
			continue
		}
		if limits.MaxRecordBytes > 0 && int64(len(encoded)) > limits.MaxRecordBytes {
			oversizedRecords++
			continue
		}
		var rec AuditRecord
		if err := json.Unmarshal(encoded, &rec); err != nil {
			continue
		}
		rec.Raw = raw
		records = append(records, rec)
	}

	return records, oversizedRecords, nil
}
