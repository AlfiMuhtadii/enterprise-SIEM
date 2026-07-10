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
	"io"
)

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
func Parse(data []byte) ([]Record, error) {
	if isGzip(data) {
		decompressed, err := gunzip(data)
		if err != nil {
			return nil, err
		}
		data = decompressed
	}

	var batch recordBatch
	if err := json.Unmarshal(data, &batch); err != nil {
		return nil, err
	}

	records := make([]Record, 0, len(batch.Records))
	for _, raw := range batch.Records {
		var rec Record
		// Re-marshal/unmarshal the individual record map into the typed
		// struct so unknown/varying fields (requestParameters, etc.) are
		// simply ignored by the typed decode while still available raw.
		encoded, err := json.Marshal(raw)
		if err != nil {
			continue
		}
		if err := json.Unmarshal(encoded, &rec); err != nil {
			continue
		}
		rec.Raw = raw
		records = append(records, rec)
	}

	return records, nil
}

func isGzip(data []byte) bool {
	return len(data) >= 2 && data[0] == 0x1f && data[1] == 0x8b
}

func gunzip(data []byte) ([]byte, error) {
	r, err := gzip.NewReader(bytes.NewReader(data))
	if err != nil {
		return nil, err
	}
	defer func() { _ = r.Close() }()
	return io.ReadAll(r)
}
