// Package leef parses IBM QRadar Log Event Extended Format (LEEF) messages,
// a common alternative to CEF used by many SIEM-integrated appliances
// (firewalls, proxies, IAM systems) that emit over syslog.
//
// Format (LEEF 1.0): LEEF:Version|Vendor|Product|Product Version|Event ID|Extension
// Format (LEEF 2.0): LEEF:Version|Vendor|Product|Product Version|Event ID|Delimiter|Extension
//
// LEEF 1.0 extension key=value pairs are tab-delimited. LEEF 2.0 makes the
// extension delimiter explicit as its own header field (a literal single
// character, or a hex byte written as "x09"/"0x09").
//
// This is a pure, side-effect-free parser — no network/transport concerns —
// matching the internal/cef package's structure and precedent.
package leef

import (
	"errors"
	"strconv"
	"strings"
)

// ErrNotLEEF is returned when the input line contains no "LEEF:" marker at all.
var ErrNotLEEF = errors.New("not_leef_format")

// ErrMalformed is returned when a "LEEF:" marker is present but the required
// pipe-delimited header fields cannot all be extracted, or the delimiter
// field (LEEF 2.0) cannot be parsed.
var ErrMalformed = errors.New("malformed_leef_header")

// ErrUnsupportedVersion is returned for any LEEF version other than 1.x/2.x.
var ErrUnsupportedVersion = errors.New("unsupported_leef_version")

// Message is a parsed LEEF event. Extension holds every extension key
// verbatim (not just a known subset) so no vendor-specific field is lost.
type Message struct {
	Version        string
	Vendor         string
	Product        string
	ProductVersion string
	EventID        string
	Delimiter      byte
	Extension      map[string]string
}

// Parse extracts a LEEF message from a raw log line. Any syslog header
// preceding the "LEEF:" marker is discarded — only the LEEF payload itself
// is parsed, matching internal/cef.Parse's envelope-stripping behavior.
func Parse(line string) (*Message, error) {
	idx := strings.Index(line, "LEEF:")
	if idx == -1 {
		return nil, ErrNotLEEF
	}
	payload := line[idx+len("LEEF:"):]

	verFields, rest := splitPipeFields(payload, 1)
	if len(verFields) != 1 {
		return nil, ErrMalformed
	}
	version := strings.TrimSpace(verFields[0])

	var neededFields int
	var delim byte = '\t'
	isV2 := false
	switch {
	case strings.HasPrefix(version, "1."):
		neededFields = 4
	case strings.HasPrefix(version, "2."):
		neededFields = 5
		isV2 = true
	default:
		return nil, ErrUnsupportedVersion
	}

	fields, extensionRaw := splitPipeFields(rest, neededFields)
	if len(fields) != neededFields {
		return nil, ErrMalformed
	}
	for i := range fields {
		fields[i] = strings.TrimSpace(fields[i])
	}

	if isV2 {
		d, err := parseDelimiter(fields[4])
		if err != nil {
			return nil, ErrMalformed
		}
		delim = d
	}

	return &Message{
		Version:        version,
		Vendor:         fields[0],
		Product:        fields[1],
		ProductVersion: fields[2],
		EventID:        fields[3],
		Delimiter:      delim,
		Extension:      parseExtension(extensionRaw, delim),
	}, nil
}

// splitPipeFields splits on unescaped '|' (honoring "\|"/"\\" escaping)
// until n fields have been produced, then returns those fields plus
// whatever remains unsplit (raw, still escaped) as rest. If fewer than n
// unescaped pipes are found, fields holds everything collected and rest is
// empty — the caller detects this as malformed via a field-count check.
func splitPipeFields(payload string, n int) (fields []string, rest string) {
	var b strings.Builder
	escaped := false
	for i := 0; i < len(payload); i++ {
		c := payload[i]
		if escaped {
			b.WriteByte(c)
			escaped = false
			continue
		}
		if c == '\\' {
			escaped = true
			continue
		}
		if c == '|' {
			fields = append(fields, b.String())
			b.Reset()
			if len(fields) == n {
				return fields, payload[i+1:]
			}
			continue
		}
		b.WriteByte(c)
	}
	fields = append(fields, b.String())
	return fields, ""
}

// parseDelimiter decodes a LEEF 2.0 delimiter field: either a literal single
// character, or a hex byte written as "x09" / "0x09".
func parseDelimiter(s string) (byte, error) {
	if len(s) == 1 {
		return s[0], nil
	}
	lower := strings.ToLower(s)
	var hexPart string
	switch {
	case strings.HasPrefix(lower, "0x"):
		hexPart = s[2:]
	case strings.HasPrefix(lower, "x"):
		hexPart = s[1:]
	default:
		return 0, ErrMalformed
	}
	v, err := strconv.ParseUint(hexPart, 16, 8)
	if err != nil {
		return 0, ErrMalformed
	}
	return byte(v), nil
}

// splitExtension splits raw on unescaped occurrences of delim, honoring
// "\<delim>" and "\\" escaping (resolved in the same pass — the escaped
// byte is written literally into the current token).
func splitExtension(raw string, delim byte) []string {
	var out []string
	var b strings.Builder
	escaped := false
	for i := 0; i < len(raw); i++ {
		c := raw[i]
		if escaped {
			b.WriteByte(c)
			escaped = false
			continue
		}
		if c == '\\' {
			escaped = true
			continue
		}
		if c == delim {
			out = append(out, b.String())
			b.Reset()
			continue
		}
		b.WriteByte(c)
	}
	out = append(out, b.String())
	return out
}

// parseExtension splits a LEEF extension string into key/value pairs using
// the message's delimiter (tab for LEEF 1.0, the explicit field for 2.0).
func parseExtension(raw string, delim byte) map[string]string {
	ext := map[string]string{}
	if strings.TrimSpace(raw) == "" {
		return ext
	}
	for _, token := range splitExtension(raw, delim) {
		token = strings.TrimSpace(token)
		if token == "" {
			continue
		}
		eq := strings.IndexByte(token, '=')
		if eq < 0 {
			continue
		}
		key := token[:eq]
		if key == "" {
			continue
		}
		ext[key] = token[eq+1:]
	}
	return ext
}
