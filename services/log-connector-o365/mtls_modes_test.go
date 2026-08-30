package main

import "testing"

func TestInternalMtlsModes(t *testing.T) {
	tests := []struct {
		name       string
		serverEnv  string
		clientEnv  string
		wantServer bool
		wantClient bool
	}{
		{name: "disabled by default"},
		{name: "client inherits enabled server", serverEnv: "true", wantServer: true, wantClient: true},
		{name: "client only rollout", clientEnv: "true", wantClient: true},
		{name: "server only override", serverEnv: "true", clientEnv: "false", wantServer: true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			t.Setenv("XDR_INTERNAL_MTLS_ENABLED", tt.serverEnv)
			t.Setenv("XDR_INTERNAL_MTLS_CLIENT_ENABLED", tt.clientEnv)

			serverEnabled, clientEnabled := internalMtlsModes()
			if serverEnabled != tt.wantServer || clientEnabled != tt.wantClient {
				t.Fatalf(
					"internalMtlsModes() = (%v, %v), want (%v, %v)",
					serverEnabled, clientEnabled, tt.wantServer, tt.wantClient,
				)
			}
		})
	}
}
