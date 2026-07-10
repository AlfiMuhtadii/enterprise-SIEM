// Package cloudtrail parses AWS CloudTrail log file exports — the stable,
// well-documented `{"Records": [...]}` JSON format CloudTrail writes to S3,
// gzip-compressed by default (`AWSLogs/<account>/CloudTrail/<region>/...
// .json.gz`).
//
// This is a pure, side-effect-free parser — no network/filesystem concerns
// — matching the internal/cef and internal/leef package pattern already
// used for the syslog connector.
package cloudtrail

import (
	"bytes"
	"compress/gzip"
	"encoding/json"
	"errors"
	"io"
)

// ErrExpandedTooLarge is returned by ParseBounded when a gzip-compressed
// file's decompressed content exceeds Limits.MaxExpandedBytes — the
// defense against a compression-bomb export file (CONN-UNBOUNDED-FILE).
var ErrExpandedTooLarge = errors.New("decompressed content exceeds configured size limit")

// Limits bounds ParseBounded's resource usage. Zero value means "no limit"
// on that dimension, matching Parse's original unbounded behavior.
type Limits struct {
	// MaxExpandedBytes caps how much decompressed data a gzip-compressed
	// file is allowed to produce. 0 = unlimited.
	MaxExpandedBytes int64
	// MaxRecordBytes caps the re-marshaled JSON size of a single Records[]
	// entry. An entry over this limit is counted in the returned
	// oversizedRecords count and skipped (not silently discarded — the
	// caller is expected to log/quarantine using that count), never
	// unmarshaled into the typed struct. 0 = unlimited.
	MaxRecordBytes int64
}

// UserIdentity is the CloudTrail record's actor-identity block. Only the
// fields commonly populated across identity types (IAMUser, AssumedRole,
// Root, AWSService, ...) are modeled; anything else is still preserved
// verbatim in Record.Raw.
type UserIdentity struct {
	Type        string `json:"type"`
	PrincipalID string `json:"principalId"`
	ARN         string `json:"arn"`
	AccountID   string `json:"accountId"`
	UserName    string `json:"userName"`
	AccessKeyID string `json:"accessKeyId"`
	InvokedBy   string `json:"invokedBy"`
}

// Record is one parsed CloudTrail event. Raw preserves the entire original
// record (including requestParameters/responseElements, whose shape varies
// per API call) so no detail is lost even though only common fields are
// promoted to named struct fields.
type Record struct {
	EventVersion       string         `json:"eventVersion"`
	EventTime          string         `json:"eventTime"`
	EventSource        string         `json:"eventSource"`
	EventName          string         `json:"eventName"`
	AWSRegion          string         `json:"awsRegion"`
	SourceIPAddress    string         `json:"sourceIPAddress"`
	UserAgent          string         `json:"userAgent"`
	UserIdentity       UserIdentity   `json:"userIdentity"`
	RequestID          string         `json:"requestID"`
	EventID            string         `json:"eventID"`
	EventType          string         `json:"eventType"`
	RecipientAccountID string         `json:"recipientAccountId"`
	ErrorCode          string         `json:"errorCode"`
	ErrorMessage       string         `json:"errorMessage"`
	ManagementEvent    bool           `json:"managementEvent"`
	ReadOnly           *bool          `json:"readOnly"`
	Raw                map[string]any `json:"-"`
}

type recordBatch struct {
	Records []map[string]any `json:"Records"`
}

// Parse decodes a CloudTrail export file. data may be gzip-compressed (the
// default CloudTrail S3 export format, detected via the gzip magic bytes
// 0x1f 0x8b) or plain JSON — the caller does not need to know which.
// Equivalent to ParseBounded with no limits.
func Parse(data []byte) ([]Record, error) {
	records, _, err := ParseBounded(data, Limits{})
	return records, err
}

// ParseBounded is Parse with CONN-UNBOUNDED-FILE size ceilings: gzip
// expansion is capped at limits.MaxExpandedBytes (ErrExpandedTooLarge if
// exceeded — the file is rejected wholesale, since an export whose
// decompressed size can't even be measured safely can't be partially
// trusted), and any individual Records[] entry whose re-marshaled size
// exceeds limits.MaxRecordBytes is skipped and counted in the returned
// oversizedRecords rather than unmarshaled — the caller is expected to
// surface that count (metric/log/quarantine record), not treat it as a
// silent drop.
func ParseBounded(data []byte, limits Limits) (records []Record, oversizedRecords int, err error) {
	if isGzip(data) {
		decompressed, gzErr := gunzip(data, limits.MaxExpandedBytes)
		if gzErr != nil {
			return nil, 0, gzErr
		}
		data = decompressed
	}

	var batch recordBatch
	if err := json.Unmarshal(data, &batch); err != nil {
		return nil, 0, err
	}

	records = make([]Record, 0, len(batch.Records))
	for _, raw := range batch.Records {
		// Re-marshal/unmarshal the individual record map into the typed
		// struct so unknown/varying fields (requestParameters, etc.) are
		// simply ignored by the typed decode while still available raw.
		encoded, err := json.Marshal(raw)
		if err != nil {
			continue
		}
		if limits.MaxRecordBytes > 0 && int64(len(encoded)) > limits.MaxRecordBytes {
			oversizedRecords++
			continue
		}
		var rec Record
		if err := json.Unmarshal(encoded, &rec); err != nil {
			continue
		}
		rec.Raw = raw
		records = append(records, rec)
	}

	return records, oversizedRecords, nil
}

func isGzip(data []byte) bool {
	return len(data) >= 2 && data[0] == 0x1f && data[1] == 0x8b
}

func gunzip(data []byte, maxExpandedBytes int64) ([]byte, error) {
	r, err := gzip.NewReader(bytes.NewReader(data))
	if err != nil {
		return nil, err
	}
	defer func() { _ = r.Close() }()

	if maxExpandedBytes <= 0 {
		return io.ReadAll(r)
	}

	out, err := io.ReadAll(io.LimitReader(r, maxExpandedBytes+1))
	if err != nil {
		return nil, err
	}
	if int64(len(out)) > maxExpandedBytes {
		return nil, ErrExpandedTooLarge
	}
	return out, nil
}
