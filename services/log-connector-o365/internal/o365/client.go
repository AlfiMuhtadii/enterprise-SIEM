package o365

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
)

// ErrContentTooLarge is returned by FetchContent when a content blob
// exceeds the configured byte ceiling — the same CONN-UNBOUNDED-FILE
// defense the file-based connectors apply to on-disk exports, applied here
// to an HTTP response body instead (one oversized/malicious content blob
// must not be allowed to exhaust memory).
var ErrContentTooLarge = errors.New("content blob exceeds configured size limit")

// ContentPointer is one entry from the "available content" listing —
// a reference to a content blob, not the audit records themselves.
type ContentPointer struct {
	ContentURI        string `json:"contentUri"`
	ContentID         string `json:"contentId"`
	ContentType       string `json:"contentType"`
	ContentCreated    string `json:"contentCreated"`
	ContentExpiration string `json:"contentExpiration"`
}

// Client talks to the Office 365 Management Activity API
// (https://manage.office.com/api/v1.0/{tenantId}/activity/feed/...).
type Client struct {
	BaseURL    string // e.g. https://manage.office.com
	TenantID   string
	Tokens     *TokenSource
	HTTPClient *http.Client
}

func (c *Client) httpClient() *http.Client {
	if c.HTTPClient != nil {
		return c.HTTPClient
	}
	return http.DefaultClient
}

// ListAvailableContent returns every content pointer for contentType in
// [startTime, endTime), following the API's NextPageUri pagination header
// until exhausted. startTime/endTime are ISO8601 and optional (the API
// defaults to the last 24h when omitted).
func (c *Client) ListAvailableContent(contentType, startTime, endTime string) ([]ContentPointer, error) {
	token, err := c.Tokens.Token()
	if err != nil {
		return nil, fmt.Errorf("auth: %w", err)
	}

	url := fmt.Sprintf("%s/api/v1.0/%s/activity/feed/subscriptions/content?contentType=%s", c.BaseURL, c.TenantID, contentType)
	if startTime != "" {
		url += "&startTime=" + startTime
	}
	if endTime != "" {
		url += "&endTime=" + endTime
	}

	var all []ContentPointer
	for url != "" {
		req, err := http.NewRequest(http.MethodGet, url, nil)
		if err != nil {
			return nil, err
		}
		req.Header.Set("Authorization", "Bearer "+token)

		resp, err := c.httpClient().Do(req)
		if err != nil {
			return nil, err
		}
		body, readErr := io.ReadAll(io.LimitReader(resp.Body, 10<<20))
		_ = resp.Body.Close()
		if readErr != nil {
			return nil, readErr
		}
		if resp.StatusCode < 200 || resp.StatusCode >= 300 {
			return nil, fmt.Errorf("list_content_status=%d body=%s", resp.StatusCode, body)
		}

		var page []ContentPointer
		if err := json.Unmarshal(body, &page); err != nil {
			return nil, fmt.Errorf("list_content_invalid_json: %w", err)
		}
		all = append(all, page...)

		url = resp.Header.Get("NextPageUri")
	}

	return all, nil
}

// FetchContent downloads one content blob, bounded to maxBytes (0 =
// unlimited) — mirrors internal/boundedfile.Read's technique (LimitReader
// to maxBytes+1, reject if the actual body exceeds the limit) applied to
// an HTTP response body instead of a local file.
func (c *Client) FetchContent(contentURI string, maxBytes int64) ([]byte, error) {
	token, err := c.Tokens.Token()
	if err != nil {
		return nil, fmt.Errorf("auth: %w", err)
	}

	req, err := http.NewRequest(http.MethodGet, contentURI, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+token)

	resp, err := c.httpClient().Do(req)
	if err != nil {
		return nil, err
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
		return nil, fmt.Errorf("fetch_content_status=%d", resp.StatusCode)
	}

	if maxBytes <= 0 {
		return io.ReadAll(resp.Body)
	}
	data, err := io.ReadAll(io.LimitReader(resp.Body, maxBytes+1))
	if err != nil {
		return nil, err
	}
	if int64(len(data)) > maxBytes {
		return nil, ErrContentTooLarge
	}
	return data, nil
}
