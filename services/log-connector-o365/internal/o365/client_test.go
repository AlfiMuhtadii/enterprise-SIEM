package o365

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func newTestTokenSource(server *httptest.Server) *TokenSource {
	return &TokenSource{TokenURL: server.URL + "/token", ClientID: "c", ClientSecret: "s", Resource: "r"}
}

func TestListAvailableContentSendsBearerToken(t *testing.T) {
	var capturedAuth string
	mux := http.NewServeMux()
	mux.HandleFunc("/token", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"access_token":"tok-123","expires_in":3600}`))
	})
	mux.HandleFunc("/api/v1.0/tenant-1/activity/feed/subscriptions/content", func(w http.ResponseWriter, r *http.Request) {
		capturedAuth = r.Header.Get("Authorization")
		_, _ = w.Write([]byte(`[]`))
	})
	server := httptest.NewServer(mux)
	defer server.Close()

	c := &Client{BaseURL: server.URL, TenantID: "tenant-1", Tokens: newTestTokenSource(server)}
	_, err := c.ListAvailableContent("Audit.AzureActiveDirectory", "", "")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if capturedAuth != "Bearer tok-123" {
		t.Errorf("expected Authorization: Bearer tok-123, got %q", capturedAuth)
	}
}

func TestListAvailableContentParsesContentPointers(t *testing.T) {
	mux := http.NewServeMux()
	mux.HandleFunc("/token", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"access_token":"tok","expires_in":3600}`))
	})
	mux.HandleFunc("/api/v1.0/tenant-1/activity/feed/subscriptions/content", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`[{"contentUri":"https://x/blob1","contentId":"id1","contentType":"Audit.General"}]`))
	})
	server := httptest.NewServer(mux)
	defer server.Close()

	c := &Client{BaseURL: server.URL, TenantID: "tenant-1", Tokens: newTestTokenSource(server)}
	pointers, err := c.ListAvailableContent("Audit.General", "", "")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(pointers) != 1 || pointers[0].ContentID != "id1" || pointers[0].ContentURI != "https://x/blob1" {
		t.Fatalf("unexpected pointers: %+v", pointers)
	}
}

func TestListAvailableContentFollowsNextPageUriPagination(t *testing.T) {
	var requestCount int
	mux := http.NewServeMux()
	mux.HandleFunc("/token", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"access_token":"tok","expires_in":3600}`))
	})
	mux.HandleFunc("/page2", func(w http.ResponseWriter, r *http.Request) {
		requestCount++
		_, _ = w.Write([]byte(`[{"contentId":"id2"}]`))
	})
	server := httptest.NewServer(mux)
	defer server.Close()

	// Wire the NextPageUri to the real server URL now that we know it.
	mux.HandleFunc("/api/v1.0/tenant-1/activity/feed/subscriptions/content", func(w http.ResponseWriter, r *http.Request) {
		requestCount++
		w.Header().Set("NextPageUri", server.URL+"/page2")
		_, _ = w.Write([]byte(`[{"contentId":"id1"}]`))
	})

	c := &Client{BaseURL: server.URL, TenantID: "tenant-1", Tokens: newTestTokenSource(server)}
	pointers, err := c.ListAvailableContent("Audit.General", "", "")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(pointers) != 2 {
		t.Fatalf("expected 2 content pointers across 2 pages, got %d: %+v", len(pointers), pointers)
	}
	if pointers[0].ContentID != "id1" || pointers[1].ContentID != "id2" {
		t.Fatalf("unexpected pointer order/content: %+v", pointers)
	}
	if requestCount != 2 {
		t.Errorf("expected exactly 2 HTTP requests (1 per page), got %d", requestCount)
	}
}

func TestListAvailableContentReturnsErrorOnNon2xx(t *testing.T) {
	mux := http.NewServeMux()
	mux.HandleFunc("/token", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"access_token":"tok","expires_in":3600}`))
	})
	mux.HandleFunc("/api/v1.0/tenant-1/activity/feed/subscriptions/content", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusForbidden)
	})
	server := httptest.NewServer(mux)
	defer server.Close()

	c := &Client{BaseURL: server.URL, TenantID: "tenant-1", Tokens: newTestTokenSource(server)}
	if _, err := c.ListAvailableContent("Audit.General", "", ""); err == nil {
		t.Fatal("expected an error on a 403 response")
	}
}

func TestListAvailableContentPropagatesAuthError(t *testing.T) {
	mux := http.NewServeMux()
	mux.HandleFunc("/token", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
	})
	server := httptest.NewServer(mux)
	defer server.Close()

	c := &Client{BaseURL: server.URL, TenantID: "tenant-1", Tokens: newTestTokenSource(server)}
	_, err := c.ListAvailableContent("Audit.General", "", "")
	if err == nil || !strings.Contains(err.Error(), "auth:") {
		t.Fatalf("expected an auth-prefixed error, got: %v", err)
	}
}

func TestFetchContentSendsBearerTokenAndReturnsBody(t *testing.T) {
	var capturedAuth string
	mux := http.NewServeMux()
	mux.HandleFunc("/token", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"access_token":"tok-456","expires_in":3600}`))
	})
	mux.HandleFunc("/blob1", func(w http.ResponseWriter, r *http.Request) {
		capturedAuth = r.Header.Get("Authorization")
		_, _ = w.Write([]byte(`[{"Id":"rec-1"}]`))
	})
	server := httptest.NewServer(mux)
	defer server.Close()

	c := &Client{BaseURL: server.URL, TenantID: "tenant-1", Tokens: newTestTokenSource(server)}
	data, err := c.FetchContent(server.URL+"/blob1", 0)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if capturedAuth != "Bearer tok-456" {
		t.Errorf("expected Authorization: Bearer tok-456, got %q", capturedAuth)
	}
	if string(data) != `[{"Id":"rec-1"}]` {
		t.Errorf("unexpected body: %s", data)
	}
}

func TestFetchContentRejectsOversizedBlob(t *testing.T) {
	mux := http.NewServeMux()
	mux.HandleFunc("/token", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"access_token":"tok","expires_in":3600}`))
	})
	mux.HandleFunc("/blob1", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(strings.Repeat("x", 10000)))
	})
	server := httptest.NewServer(mux)
	defer server.Close()

	c := &Client{BaseURL: server.URL, TenantID: "tenant-1", Tokens: newTestTokenSource(server)}
	_, err := c.FetchContent(server.URL+"/blob1", 100)
	if !errors.Is(err, ErrContentTooLarge) {
		t.Fatalf("expected ErrContentTooLarge, got: %v", err)
	}
}

func TestFetchContentAllowsBlobUnderLimit(t *testing.T) {
	mux := http.NewServeMux()
	mux.HandleFunc("/token", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"access_token":"tok","expires_in":3600}`))
	})
	mux.HandleFunc("/blob1", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`[{"Id":"rec-1"}]`))
	})
	server := httptest.NewServer(mux)
	defer server.Close()

	c := &Client{BaseURL: server.URL, TenantID: "tenant-1", Tokens: newTestTokenSource(server)}
	data, err := c.FetchContent(server.URL+"/blob1", 1_000_000)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(data) == 0 {
		t.Error("expected non-empty body")
	}
}

func TestFetchContentReturnsErrorOnNon2xx(t *testing.T) {
	mux := http.NewServeMux()
	mux.HandleFunc("/token", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"access_token":"tok","expires_in":3600}`))
	})
	mux.HandleFunc("/blob1", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNotFound)
	})
	server := httptest.NewServer(mux)
	defer server.Close()

	c := &Client{BaseURL: server.URL, TenantID: "tenant-1", Tokens: newTestTokenSource(server)}
	if _, err := c.FetchContent(server.URL+"/blob1", 0); err == nil {
		t.Fatal("expected an error on a 404 response")
	}
}
