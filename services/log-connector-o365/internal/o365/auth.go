// Package o365 implements the OAuth2 client-credentials auth flow and the
// Office 365 Management Activity API client (subscription content listing +
// content blob fetch), plus a pure parser for the audit records inside a
// content blob.
//
// Unlike log-connector-{cloudtrail,guardduty,gcp-audit} (file-based
// ingestion of already-exported logs), the Management Activity API is a
// live pull-only API: there is no "already exported to a bucket" fallback
// for O365 audit data — an operator must have a real Azure AD app
// registration (client ID/secret, tenant ID) with Activity Feed API
// permissions granted, and an active subscription
// (POST .../activity/feed/subscriptions/start) before this connector can
// list any content. This environment has no such credentials, so the auth/
// listing/fetch logic here is built and unit-tested against a local mock
// OAuth token endpoint + mock Activity API server — proven correct in
// isolation, but never exercised against a real Microsoft tenant.
package o365

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"
)

// TokenResponse is Azure AD's OAuth2 client-credentials grant response
// (https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token).
type TokenResponse struct {
	AccessToken string `json:"access_token"`
	TokenType   string `json:"token_type"`
	ExpiresIn   int64  `json:"expires_in"`
}

// TokenSource fetches and caches an OAuth2 client-credentials token,
// refreshing it shortly before expiry rather than on every request. Safe
// for concurrent use.
type TokenSource struct {
	TokenURL     string // e.g. https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token
	ClientID     string
	ClientSecret string
	Resource     string // v1.0 Activity API uses "resource", not the newer "scope" param
	HTTPClient   *http.Client

	mu        sync.Mutex
	cached    string
	expiresAt time.Time
	// now is overridable in tests; defaults to time.Now.
	now func() time.Time
}

// refreshSkew is how far before the token's actual expiry a cached token is
// treated as stale, so a request never races an expiring token mid-flight.
const refreshSkew = 60 * time.Second

// Token returns a valid access token, fetching a fresh one from TokenURL if
// the cached token is missing or within refreshSkew of expiring.
func (t *TokenSource) Token() (string, error) {
	t.mu.Lock()
	defer t.mu.Unlock()

	nowFn := t.now
	if nowFn == nil {
		nowFn = time.Now
	}

	if t.cached != "" && nowFn().Before(t.expiresAt) {
		return t.cached, nil
	}

	client := t.HTTPClient
	if client == nil {
		client = http.DefaultClient
	}

	form := url.Values{}
	form.Set("grant_type", "client_credentials")
	form.Set("client_id", t.ClientID)
	form.Set("client_secret", t.ClientSecret)
	form.Set("resource", t.Resource)

	req, err := http.NewRequest(http.MethodPost, t.TokenURL, strings.NewReader(form.Encode()))
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")

	resp, err := client.Do(req)
	if err != nil {
		return "", err
	}
	defer func() { _ = resp.Body.Close() }()

	body, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return "", err
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return "", fmt.Errorf("token_endpoint_status=%d body=%s", resp.StatusCode, body)
	}

	var tok TokenResponse
	if err := json.Unmarshal(body, &tok); err != nil {
		return "", fmt.Errorf("token_endpoint_invalid_json: %w", err)
	}
	if tok.AccessToken == "" {
		return "", fmt.Errorf("token_endpoint_empty_access_token")
	}

	t.cached = tok.AccessToken
	expiresIn := time.Duration(tok.ExpiresIn) * time.Second
	if expiresIn <= refreshSkew {
		// A token endpoint returning an implausibly short (or zero/negative)
		// expires_in must never produce a negative refresh window — fall
		// back to treating it as immediately stale rather than caching it
		// past its real expiry.
		t.expiresAt = nowFn()
	} else {
		t.expiresAt = nowFn().Add(expiresIn - refreshSkew)
	}

	return t.cached, nil
}
