// Package mtls provides mutual-TLS tls.Config builders for internal
// service-to-service HTTP hops (ENT-SEC-NO-TLS-INTERNAL, phase 1).
//
// Disabled by default everywhere (ServerConfig/ClientConfig return a nil
// *tls.Config, meaning "use plain HTTP") — callers must explicitly pass
// enabled=true (wired to XDR_INTERNAL_MTLS_ENABLED) to opt in. This keeps
// every existing deployment byte-for-byte unaffected until an operator
// generates certs (scripts/xdr_generate_internal_mtls_certs.py) and flips
// the flag on.
package mtls

import (
	"crypto/tls"
	"crypto/x509"
	"fmt"
	"os"
)

// ServerConfig builds a *tls.Config for an internal HTTP server that must
// require and verify a client certificate signed by the given CA before
// accepting a connection. Returns (nil, nil) when enabled is false.
func ServerConfig(enabled bool, certFile, keyFile, caFile string) (*tls.Config, error) {
	if !enabled {
		return nil, nil
	}
	cert, err := tls.LoadX509KeyPair(certFile, keyFile)
	if err != nil {
		return nil, fmt.Errorf("mtls: load server cert/key: %w", err)
	}
	caPool, err := loadCAPool(caFile)
	if err != nil {
		return nil, err
	}
	return &tls.Config{
		Certificates: []tls.Certificate{cert},
		ClientCAs:    caPool,
		ClientAuth:   tls.RequireAndVerifyClientCert,
		MinVersion:   tls.VersionTLS12,
	}, nil
}

// ClientConfig builds a *tls.Config for an internal HTTP client that must
// present its own client certificate and verify the server's certificate
// against the given CA. Returns (nil, nil) when enabled is false.
func ClientConfig(enabled bool, certFile, keyFile, caFile string) (*tls.Config, error) {
	if !enabled {
		return nil, nil
	}
	cert, err := tls.LoadX509KeyPair(certFile, keyFile)
	if err != nil {
		return nil, fmt.Errorf("mtls: load client cert/key: %w", err)
	}
	caPool, err := loadCAPool(caFile)
	if err != nil {
		return nil, err
	}
	return &tls.Config{
		Certificates: []tls.Certificate{cert},
		RootCAs:      caPool,
		MinVersion:   tls.VersionTLS12,
	}, nil
}

func loadCAPool(caFile string) (*x509.CertPool, error) {
	raw, err := os.ReadFile(caFile)
	if err != nil {
		return nil, fmt.Errorf("mtls: read CA file: %w", err)
	}
	pool := x509.NewCertPool()
	if !pool.AppendCertsFromPEM(raw) {
		return nil, fmt.Errorf("mtls: no valid certificates found in CA file %s", caFile)
	}
	return pool, nil
}
