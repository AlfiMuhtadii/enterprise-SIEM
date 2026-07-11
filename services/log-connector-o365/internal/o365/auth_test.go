package o365

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strconv"
	"sync/atomic"
	"testing"
	"time"
)

func newTokenServer(t *testing.T, expiresIn int64) (*httptest.Server, *int32) {
	t.Helper()
	var calls int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&calls, 1)
		if r.Method != http.MethodPost {
			t.Errorf("expected POST, got %s", r.Method)
		}
		if r.Header.Get("Content-Type") != "application/x-www-form-urlencoded" {
			t.Errorf("expected form content-type, got %q", r.Header.Get("Content-Type"))
		}
		_ = r.ParseForm()
		if r.PostForm.Get("grant_type") != "client_credentials" {
			t.Errorf("expected grant_type=client_credentials, got %q", r.PostForm.Get("grant_type"))
		}
		if r.PostForm.Get("client_id") != "client-abc" {
			t.Errorf("expected client_id=client-abc, got %q", r.PostForm.Get("client_id"))
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(TokenResponse{
			AccessToken: "token-" + strconv.Itoa(int(atomic.LoadInt32(&calls))),
			TokenType:   "Bearer",
			ExpiresIn:   expiresIn,
		})
	}))
	return server, &calls
}

func TestTokenFetchesFromRealHTTPEndpoint(t *testing.T) {
	server, calls := newTokenServer(t, 3600)
	defer server.Close()

	ts := &TokenSource{TokenURL: server.URL, ClientID: "client-abc", ClientSecret: "secret", Resource: "https://manage.office.com"}
	tok, err := ts.Token()
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if tok != "token-1" {
		t.Errorf("expected token-1, got %q", tok)
	}
	if atomic.LoadInt32(calls) != 1 {
		t.Errorf("expected exactly 1 token request, got %d", *calls)
	}
}

func TestTokenCachesUntilNearExpiry(t *testing.T) {
	server, calls := newTokenServer(t, 3600)
	defer server.Close()

	ts := &TokenSource{TokenURL: server.URL, ClientID: "client-abc", ClientSecret: "secret", Resource: "r"}
	tok1, _ := ts.Token()
	tok2, _ := ts.Token()

	if tok1 != tok2 {
		t.Errorf("expected the cached token to be reused, got %q then %q", tok1, tok2)
	}
	if atomic.LoadInt32(calls) != 1 {
		t.Errorf("expected exactly 1 token request across 2 Token() calls, got %d", *calls)
	}
}

func TestTokenRefreshesShortlyBeforeExpiry(t *testing.T) {
	server, calls := newTokenServer(t, 100) // 100s expiry, 60s refresh skew -> stale after 40s
	defer server.Close()

	fakeNow := time.Now()
	ts := &TokenSource{TokenURL: server.URL, ClientID: "client-abc", ClientSecret: "secret", Resource: "r", now: func() time.Time { return fakeNow }}
	tok1, _ := ts.Token()

	fakeNow = fakeNow.Add(41 * time.Second) // past the 40s effective window
	tok2, _ := ts.Token()

	if tok1 == tok2 {
		t.Error("expected a fresh token to be fetched once within the refresh skew window")
	}
	if atomic.LoadInt32(calls) != 2 {
		t.Errorf("expected exactly 2 token requests, got %d", *calls)
	}
}

func TestTokenDoesNotRefreshBeforeSkewWindow(t *testing.T) {
	server, calls := newTokenServer(t, 3600)
	defer server.Close()

	fakeNow := time.Now()
	ts := &TokenSource{TokenURL: server.URL, ClientID: "client-abc", ClientSecret: "secret", Resource: "r", now: func() time.Time { return fakeNow }}
	_, _ = ts.Token()

	fakeNow = fakeNow.Add(10 * time.Minute) // well inside a 3600s expiry, 60s skew
	_, _ = ts.Token()

	if atomic.LoadInt32(calls) != 1 {
		t.Errorf("expected the token to still be cached, got %d requests", *calls)
	}
}

func TestTokenReturnsErrorOnNon2xxResponse(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
		_, _ = w.Write([]byte(`{"error":"invalid_client"}`))
	}))
	defer server.Close()

	ts := &TokenSource{TokenURL: server.URL, ClientID: "bad", ClientSecret: "bad", Resource: "r"}
	if _, err := ts.Token(); err == nil {
		t.Fatal("expected an error on a 401 response from the token endpoint")
	}
}

func TestTokenReturnsErrorOnMalformedJSON(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`not json`))
	}))
	defer server.Close()

	ts := &TokenSource{TokenURL: server.URL, ClientID: "c", ClientSecret: "s", Resource: "r"}
	if _, err := ts.Token(); err == nil {
		t.Fatal("expected an error on malformed JSON from the token endpoint")
	}
}

func TestTokenReturnsErrorWhenAccessTokenEmpty(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		_ = json.NewEncoder(w).Encode(TokenResponse{ExpiresIn: 3600})
	}))
	defer server.Close()

	ts := &TokenSource{TokenURL: server.URL, ClientID: "c", ClientSecret: "s", Resource: "r"}
	if _, err := ts.Token(); err == nil {
		t.Fatal("expected an error when access_token is empty")
	}
}

func TestTokenImplausibleExpiresInTreatedAsImmediatelyStale(t *testing.T) {
	server, calls := newTokenServer(t, 5) // 5s, below the 60s refresh skew
	defer server.Close()

	ts := &TokenSource{TokenURL: server.URL, ClientID: "client-abc", ClientSecret: "s", Resource: "r"}
	_, _ = ts.Token()
	_, _ = ts.Token() // must not panic on a negative/zero cache window, must re-fetch

	if atomic.LoadInt32(calls) != 2 {
		t.Errorf("expected a re-fetch since expiresIn <= refreshSkew, got %d calls", *calls)
	}
}

func TestTokenSendsCredentialsAsFormEncodedBody(t *testing.T) {
	var capturedBody url.Values
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = r.ParseForm()
		capturedBody = r.PostForm
		_ = json.NewEncoder(w).Encode(TokenResponse{AccessToken: "t", ExpiresIn: 3600})
	}))
	defer server.Close()

	ts := &TokenSource{TokenURL: server.URL, ClientID: "my-client", ClientSecret: "my-secret", Resource: "https://manage.office.com"}
	_, _ = ts.Token()

	if capturedBody.Get("client_secret") != "my-secret" {
		t.Errorf("expected client_secret in form body, got %q", capturedBody.Get("client_secret"))
	}
	if capturedBody.Get("resource") != "https://manage.office.com" {
		t.Errorf("expected resource in form body, got %q", capturedBody.Get("resource"))
	}
}
