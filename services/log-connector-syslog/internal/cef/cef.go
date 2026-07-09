// Package cef parses ArcSight Common Event Format (CEF) messages, the most
// widely implemented vendor-neutral log format for firewalls, WAFs, IDS/IPS,
// and other network security appliances that emit over syslog.
//
// Format: CEF:Version|Device Vendor|Device Product|Device Version|
//
//	Device Event Class ID|Name|Severity|[Extension]
//
// This is a pure, side-effect-free parser — no network/transport concerns —
// so it is independently unit-testable, matching the ioc/shadowrules package
// pattern already used in correlation-worker.
package cef

import (
	"errors"
	"regexp"
	"strconv"
	"strings"
)

// ErrNotCEF is returned when the input line contains no "CEF:" marker at all.
var ErrNotCEF = errors.New("not_cef_format")

// ErrMalformed is returned when a "CEF:" marker is present but the seven
// required pipe-delimited header fields cannot all be extracted.
var ErrMalformed = errors.New("malformed_cef_header")

// Message is a parsed CEF event. Extension holds every extension key
// verbatim (not just a known subset) so no vendor-specific field is lost.
type Message struct {
	Version       int
	DeviceVendor  string
	DeviceProduct string
	DeviceVersion string
	SignatureID   string
	Name          string
	Severity      string
	Extension     map[string]string
}

// Parse extracts a CEF message from a raw log line. Any syslog header
// preceding the "CEF:" marker (RFC3164 "<PRI>timestamp host " or RFC5424
// "<PRI>1 timestamp host app procid msgid ") is discarded — only the CEF
// payload itself is parsed; the caller is responsible for anything it needs
// from the syslog envelope.
func Parse(line string) (*Message, error) {
	idx := strings.Index(line, "CEF:")
	if idx == -1 {
		return nil, ErrNotCEF
	}
	payload := line[idx+len("CEF:"):]

	fields, extensionRaw := splitHeader(payload)
	if len(fields) != 7 {
		return nil, ErrMalformed
	}

	version, err := strconv.Atoi(strings.TrimSpace(fields[0]))
	if err != nil {
		return nil, ErrMalformed
	}

	msg := &Message{
		Version:       version,
		DeviceVendor:  fields[1],
		DeviceProduct: fields[2],
		DeviceVersion: fields[3],
		SignatureID:   fields[4],
		Name:          fields[5],
		Severity:      fields[6],
		Extension:     parseExtension(extensionRaw),
	}
	return msg, nil
}

// splitHeader splits the seven pipe-delimited CEF header fields, honoring
// "\|" and "\\" escaping within a field, and returns whatever remains after
// the seventh unescaped pipe (the raw, un-split extension string — extension
// values are not pipe-delimited so they must not be split on '|').
func splitHeader(payload string) (fields []string, extensionRaw string) {
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
			if len(fields) == 7 {
				extensionRaw = payload[i+1:]
				return fields, extensionRaw
			}
			continue
		}
		b.WriteByte(c)
	}
	fields = append(fields, b.String())
	return fields, ""
}

// extKeyPattern matches a bare "key=" boundary in a CEF extension string.
// CEF extension keys are always a contiguous word (letters/digits/dot/
// underscore) with no spaces, so this reliably finds each key= start even
// though values may themselves contain spaces.
var extKeyPattern = regexp.MustCompile(`([A-Za-z0-9_.]+)=`)

// parseExtension splits a CEF extension string into key/value pairs. Values
// run from immediately after "key=" up to (but not including) the next
// unescaped "key=" boundary, then are trimmed and unescaped.
func parseExtension(raw string) map[string]string {
	ext := map[string]string{}
	if strings.TrimSpace(raw) == "" {
		return ext
	}
	matches := extKeyPattern.FindAllStringSubmatchIndex(raw, -1)
	for i, m := range matches {
		key := raw[m[2]:m[3]]
		valStart := m[1]
		valEnd := len(raw)
		if i+1 < len(matches) {
			valEnd = matches[i+1][0]
		}
		value := strings.TrimSpace(raw[valStart:valEnd])
		if key != "" {
			ext[key] = unescapeExtensionValue(value)
		}
	}
	return ext
}

// unescapeExtensionValue resolves the CEF extension escape sequences
// ("\=", "\\", "\n") in a single left-to-right pass.
func unescapeExtensionValue(v string) string {
	var b strings.Builder
	for i := 0; i < len(v); i++ {
		if v[i] == '\\' && i+1 < len(v) {
			switch v[i+1] {
			case '=', '\\':
				b.WriteByte(v[i+1])
				i++
				continue
			case 'n':
				b.WriteByte('\n')
				i++
				continue
			}
		}
		b.WriteByte(v[i])
	}
	return b.String()
}
