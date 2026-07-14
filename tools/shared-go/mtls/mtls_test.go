package mtls

import (
	"crypto/rand"
	"crypto/rsa"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"io"
	"math/big"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"testing"
	"time"
)

// testPKI generates a real, in-memory CA + one server cert + one client
// cert (both signed by the CA), writes them as PEM files to a temp dir, and
// returns the file paths. Pure Go (crypto/x509), no external openssl
// process — deliberately independent of scripts/xdr_generate_internal_mtls_certs.py
// so this package's own tests don't depend on a second language's tooling.
type testPKI struct {
	dir                                        string
	caCrt                                      string
	serverCrt, serverKey                       string
	clientCrt, clientKey                       string
	untrustedClientCrt, untrustedClientKey     string
}

func newTestPKI(t *testing.T) *testPKI {
	t.Helper()
	dir := t.TempDir()

	caKey, caCert, caDER := generateCA(t)
	writePEM(t, filepath.Join(dir, "ca.crt"), "CERTIFICATE", caDER)

	serverKey, serverDER := generateLeaf(t, caCert, caKey, "internal-services", []string{"127.0.0.1", "localhost"})
	writePEM(t, filepath.Join(dir, "server.crt"), "CERTIFICATE", serverDER)
	writeKeyPEM(t, filepath.Join(dir, "server.key"), serverKey)

	clientKey, clientDER := generateLeaf(t, caCert, caKey, "internal-service-client", nil)
	writePEM(t, filepath.Join(dir, "client.crt"), "CERTIFICATE", clientDER)
	writeKeyPEM(t, filepath.Join(dir, "client.key"), clientKey)

	// A second, self-signed (NOT CA-signed) "client" cert — used to prove the
	// server actually rejects a certificate that isn't in its trust chain,
	// not just "any presented certificate."
	untrustedKey, untrustedDER := generateSelfSigned(t, "untrusted-client")
	writePEM(t, filepath.Join(dir, "untrusted-client.crt"), "CERTIFICATE", untrustedDER)
	writeKeyPEM(t, filepath.Join(dir, "untrusted-client.key"), untrustedKey)

	return &testPKI{
		dir:                dir,
		caCrt:              filepath.Join(dir, "ca.crt"),
		serverCrt:          filepath.Join(dir, "server.crt"),
		serverKey:          filepath.Join(dir, "server.key"),
		clientCrt:          filepath.Join(dir, "client.crt"),
		clientKey:          filepath.Join(dir, "client.key"),
		untrustedClientCrt: filepath.Join(dir, "untrusted-client.crt"),
		untrustedClientKey: filepath.Join(dir, "untrusted-client.key"),
	}
}

func generateCA(t *testing.T) (*rsa.PrivateKey, *x509.Certificate, []byte) {
	t.Helper()
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatalf("generate CA key: %v", err)
	}
	tmpl := &x509.Certificate{
		SerialNumber:          big.NewInt(1),
		Subject:               pkix.Name{CommonName: "Test Dev CA"},
		NotBefore:             time.Now().Add(-time.Hour),
		NotAfter:              time.Now().Add(time.Hour),
		KeyUsage:              x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature,
		BasicConstraintsValid: true,
		IsCA:                  true,
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		t.Fatalf("create CA cert: %v", err)
	}
	cert, err := x509.ParseCertificate(der)
	if err != nil {
		t.Fatalf("parse CA cert: %v", err)
	}
	return key, cert, der
}

func generateLeaf(t *testing.T, caCert *x509.Certificate, caKey *rsa.PrivateKey, cn string, ips []string) (*rsa.PrivateKey, []byte) {
	t.Helper()
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatalf("generate leaf key: %v", err)
	}
	tmpl := &x509.Certificate{
		SerialNumber: big.NewInt(time.Now().UnixNano()),
		Subject:      pkix.Name{CommonName: cn},
		NotBefore:    time.Now().Add(-time.Hour),
		NotAfter:     time.Now().Add(time.Hour),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth, x509.ExtKeyUsageClientAuth},
	}
	for _, ip := range ips {
		if parsed := net.ParseIP(ip); parsed != nil {
			tmpl.IPAddresses = append(tmpl.IPAddresses, parsed)
		} else {
			tmpl.DNSNames = append(tmpl.DNSNames, ip)
		}
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, caCert, &key.PublicKey, caKey)
	if err != nil {
		t.Fatalf("create leaf cert: %v", err)
	}
	return key, der
}

func generateSelfSigned(t *testing.T, cn string) (*rsa.PrivateKey, []byte) {
	t.Helper()
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatalf("generate self-signed key: %v", err)
	}
	tmpl := &x509.Certificate{
		SerialNumber: big.NewInt(2),
		Subject:      pkix.Name{CommonName: cn},
		NotBefore:    time.Now().Add(-time.Hour),
		NotAfter:     time.Now().Add(time.Hour),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		t.Fatalf("create self-signed cert: %v", err)
	}
	return key, der
}

func writePEM(t *testing.T, path, blockType string, der []byte) {
	t.Helper()
	f, err := os.Create(path)
	if err != nil {
		t.Fatalf("create %s: %v", path, err)
	}
	defer f.Close()
	if err := pem.Encode(f, &pem.Block{Type: blockType, Bytes: der}); err != nil {
		t.Fatalf("encode PEM %s: %v", path, err)
	}
}

func writeKeyPEM(t *testing.T, path string, key *rsa.PrivateKey) {
	t.Helper()
	f, err := os.Create(path)
	if err != nil {
		t.Fatalf("create %s: %v", path, err)
	}
	defer f.Close()
	if err := pem.Encode(f, &pem.Block{Type: "RSA PRIVATE KEY", Bytes: x509.MarshalPKCS1PrivateKey(key)}); err != nil {
		t.Fatalf("encode key PEM %s: %v", path, err)
	}
}

// ---------------------------------------------------------------------------
// ServerConfig / ClientConfig — disabled-by-default and error-path behavior
// ---------------------------------------------------------------------------

func TestServerConfigDisabledReturnsNil(t *testing.T) {
	cfg, err := ServerConfig(false, "any", "any", "any")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if cfg != nil {
		t.Fatal("expected nil *tls.Config when disabled")
	}
}

func TestClientConfigDisabledReturnsNil(t *testing.T) {
	cfg, err := ClientConfig(false, "any", "any", "any")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if cfg != nil {
		t.Fatal("expected nil *tls.Config when disabled")
	}
}

func TestServerConfigEnabledRequiresAndVerifiesClientCert(t *testing.T) {
	pki := newTestPKI(t)
	cfg, err := ServerConfig(true, pki.serverCrt, pki.serverKey, pki.caCrt)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if cfg == nil {
		t.Fatal("expected non-nil *tls.Config when enabled")
	}
	if cfg.ClientAuth != tls.RequireAndVerifyClientCert {
		t.Errorf("expected ClientAuth=RequireAndVerifyClientCert, got %v", cfg.ClientAuth)
	}
	if cfg.MinVersion != tls.VersionTLS12 {
		t.Errorf("expected MinVersion=TLS1.2, got %v", cfg.MinVersion)
	}
}

func TestServerConfigMissingCertFileReturnsError(t *testing.T) {
	pki := newTestPKI(t)
	_, err := ServerConfig(true, filepath.Join(pki.dir, "nonexistent.crt"), pki.serverKey, pki.caCrt)
	if err == nil {
		t.Fatal("expected an error for a missing cert file")
	}
}

func TestServerConfigMissingCAFileReturnsError(t *testing.T) {
	pki := newTestPKI(t)
	_, err := ServerConfig(true, pki.serverCrt, pki.serverKey, filepath.Join(pki.dir, "nonexistent-ca.crt"))
	if err == nil {
		t.Fatal("expected an error for a missing CA file")
	}
}

func TestServerConfigInvalidCAContentReturnsError(t *testing.T) {
	pki := newTestPKI(t)
	badCA := filepath.Join(pki.dir, "bad-ca.crt")
	if err := os.WriteFile(badCA, []byte("not a certificate"), 0o600); err != nil {
		t.Fatalf("write bad CA file: %v", err)
	}
	_, err := ServerConfig(true, pki.serverCrt, pki.serverKey, badCA)
	if err == nil {
		t.Fatal("expected an error for a CA file with no valid PEM certificates")
	}
}

func TestClientConfigEnabledSetsRootCAsAndCert(t *testing.T) {
	pki := newTestPKI(t)
	cfg, err := ClientConfig(true, pki.clientCrt, pki.clientKey, pki.caCrt)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(cfg.Certificates) != 1 {
		t.Errorf("expected exactly 1 client certificate, got %d", len(cfg.Certificates))
	}
	if cfg.RootCAs == nil {
		t.Error("expected RootCAs to be set")
	}
}

// ---------------------------------------------------------------------------
// End-to-end handshake — proves this is genuine, working mTLS, not just
// tls.Config object shape.
// ---------------------------------------------------------------------------

func TestEndToEndMutualTLSHandshakeSucceedsWithValidClientCert(t *testing.T) {
	pki := newTestPKI(t)

	serverCfg, err := ServerConfig(true, pki.serverCrt, pki.serverKey, pki.caCrt)
	if err != nil {
		t.Fatalf("ServerConfig: %v", err)
	}

	ln, err := tls.Listen("tcp", "127.0.0.1:0", serverCfg)
	if err != nil {
		t.Fatalf("tls.Listen: %v", err)
	}
	defer ln.Close()

	go func() {
		mux := http.NewServeMux()
		mux.HandleFunc("/ping", func(w http.ResponseWriter, r *http.Request) {
			_, _ = io.WriteString(w, "pong")
		})
		_ = http.Serve(ln, mux)
	}()

	clientCfg, err := ClientConfig(true, pki.clientCrt, pki.clientKey, pki.caCrt)
	if err != nil {
		t.Fatalf("ClientConfig: %v", err)
	}
	client := &http.Client{
		Transport: &http.Transport{TLSClientConfig: clientCfg},
		Timeout:   5 * time.Second,
	}

	resp, err := client.Get("https://" + ln.Addr().String() + "/ping")
	if err != nil {
		t.Fatalf("expected successful mTLS handshake + request, got error: %v", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		t.Errorf("expected 200, got %d", resp.StatusCode)
	}
}

func TestEndToEndMutualTLSRejectsUntrustedClientCert(t *testing.T) {
	pki := newTestPKI(t)

	serverCfg, err := ServerConfig(true, pki.serverCrt, pki.serverKey, pki.caCrt)
	if err != nil {
		t.Fatalf("ServerConfig: %v", err)
	}

	ln, err := tls.Listen("tcp", "127.0.0.1:0", serverCfg)
	if err != nil {
		t.Fatalf("tls.Listen: %v", err)
	}
	defer ln.Close()

	go func() {
		mux := http.NewServeMux()
		mux.HandleFunc("/ping", func(w http.ResponseWriter, r *http.Request) {
			_, _ = io.WriteString(w, "pong")
		})
		_ = http.Serve(ln, mux)
	}()

	// Client presents a cert that is NOT signed by the server's trusted CA.
	untrustedCert, err := tls.LoadX509KeyPair(pki.untrustedClientCrt, pki.untrustedClientKey)
	if err != nil {
		t.Fatalf("load untrusted client cert: %v", err)
	}
	caPool, err := loadCAPool(pki.caCrt)
	if err != nil {
		t.Fatalf("loadCAPool: %v", err)
	}
	client := &http.Client{
		Transport: &http.Transport{TLSClientConfig: &tls.Config{
			Certificates: []tls.Certificate{untrustedCert},
			RootCAs:      caPool,
		}},
		Timeout: 5 * time.Second,
	}

	_, err = client.Get("https://" + ln.Addr().String() + "/ping")
	if err == nil {
		t.Fatal("expected the handshake to fail for a client cert not signed by the trusted CA, but it succeeded")
	}
}

func TestEndToEndPlainHTTPWhenDisabled(t *testing.T) {
	// enabled=false must behave exactly like the pre-mTLS code path: a plain
	// (non-TLS) listener, no client cert required.
	serverCfg, err := ServerConfig(false, "", "", "")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if serverCfg != nil {
		t.Fatal("expected nil config for plain HTTP")
	}

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("net.Listen: %v", err)
	}
	defer ln.Close()

	go func() {
		mux := http.NewServeMux()
		mux.HandleFunc("/ping", func(w http.ResponseWriter, r *http.Request) {
			_, _ = io.WriteString(w, "pong")
		})
		_ = http.Serve(ln, mux)
	}()

	resp, err := http.Get("http://" + ln.Addr().String() + "/ping")
	if err != nil {
		t.Fatalf("expected plain HTTP request to succeed: %v", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		t.Errorf("expected 200, got %d", resp.StatusCode)
	}
}
