package kafkanative

import (
	"context"
	"crypto/rand"
	"crypto/rsa"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"math/big"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/twmb/franz-go/pkg/kfake"
)

func writeCertificate(t *testing.T, path, blockType string, der []byte) {
	t.Helper()
	if err := os.WriteFile(path, pem.EncodeToMemory(&pem.Block{Type: blockType, Bytes: der}), 0o600); err != nil {
		t.Fatalf("write %s: %v", path, err)
	}
}

func newTLSFixture(t *testing.T) (string, tls.Certificate) {
	t.Helper()
	now := time.Now()
	caKey, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}
	caTemplate := &x509.Certificate{
		SerialNumber:          big.NewInt(1),
		Subject:               pkix.Name{CommonName: "kafkanative-test-ca"},
		NotBefore:             now.Add(-time.Hour),
		NotAfter:              now.Add(time.Hour),
		IsCA:                  true,
		BasicConstraintsValid: true,
		KeyUsage:              x509.KeyUsageCertSign,
	}
	caDER, err := x509.CreateCertificate(rand.Reader, caTemplate, caTemplate, &caKey.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}
	caCert, err := x509.ParseCertificate(caDER)
	if err != nil {
		t.Fatal(err)
	}

	serverKey, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}
	serverTemplate := &x509.Certificate{
		SerialNumber: big.NewInt(2),
		Subject:      pkix.Name{CommonName: "127.0.0.1"},
		NotBefore:    now.Add(-time.Hour),
		NotAfter:     now.Add(time.Hour),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		IPAddresses:  []net.IP{net.ParseIP("127.0.0.1")},
	}
	serverDER, err := x509.CreateCertificate(rand.Reader, serverTemplate, caCert, &serverKey.PublicKey, caKey)
	if err != nil {
		t.Fatal(err)
	}

	dir := t.TempDir()
	caPath := filepath.Join(dir, "ca.crt")
	serverCertPath := filepath.Join(dir, "server.crt")
	serverKeyPath := filepath.Join(dir, "server.key")
	writeCertificate(t, caPath, "CERTIFICATE", caDER)
	writeCertificate(t, serverCertPath, "CERTIFICATE", serverDER)
	writeCertificate(t, serverKeyPath, "RSA PRIVATE KEY", x509.MarshalPKCS1PrivateKey(serverKey))
	serverCert, err := tls.LoadX509KeyPair(serverCertPath, serverKeyPath)
	if err != nil {
		t.Fatal(err)
	}
	return caPath, serverCert
}

func TestLoadTLSConfigDisabledPreservesPlaintext(t *testing.T) {
	cfg, err := LoadTLSConfig(false, "missing", "partial", "")
	if err != nil || cfg != nil {
		t.Fatalf("disabled TLS must return (nil, nil), got cfg=%v err=%v", cfg, err)
	}
}

func TestLoadTLSConfigRejectsIncompleteOrInvalidTrust(t *testing.T) {
	if _, err := LoadTLSConfig(true, "", "", ""); err == nil {
		t.Fatal("expected missing CA to fail")
	}
	badCA := filepath.Join(t.TempDir(), "bad-ca.crt")
	if err := os.WriteFile(badCA, []byte("not PEM"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := LoadTLSConfig(true, badCA, "", ""); err == nil {
		t.Fatal("expected invalid CA to fail")
	}
	if _, err := LoadTLSConfig(true, badCA, "client.crt", ""); err == nil {
		t.Fatal("expected partial client certificate configuration to fail")
	}
}

func TestProducerAndConsumerUseVerifiedTLS(t *testing.T) {
	caPath, serverCert := newTLSFixture(t)
	cluster, err := kfake.NewCluster(
		kfake.TLS(&tls.Config{Certificates: []tls.Certificate{serverCert}, MinVersion: tls.VersionTLS12}),
		kfake.SeedTopics(1, "tls-events"),
	)
	if err != nil {
		t.Fatal(err)
	}
	defer cluster.Close()

	clientTLS, err := LoadTLSConfig(true, caPath, "", "")
	if err != nil {
		t.Fatal(err)
	}
	producer, err := NewProducerTLS(cluster.ListenAddrs(), clientTLS)
	if err != nil {
		t.Fatal(err)
	}
	defer producer.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := producer.Publish(ctx, "tls-events", [][]byte{[]byte("verified")}); err != nil {
		t.Fatalf("TLS publish failed: %v", err)
	}

	consumer, err := NewConsumerTLS(cluster.ListenAddrs(), "tls-test", []string{"tls-events"}, clientTLS)
	if err != nil {
		t.Fatal(err)
	}
	defer consumer.Close()
	records, err := consumer.Poll(ctx)
	if err != nil {
		t.Fatalf("TLS poll failed: %v", err)
	}
	if len(records) != 1 || string(records[0].Value) != "verified" {
		t.Fatalf("unexpected TLS records: %#v", records)
	}
}

func TestProducerRejectsBrokerSignedByUntrustedCA(t *testing.T) {
	_, serverCert := newTLSFixture(t)
	wrongCAPath, _ := newTLSFixture(t)
	cluster, err := kfake.NewCluster(
		kfake.TLS(&tls.Config{Certificates: []tls.Certificate{serverCert}, MinVersion: tls.VersionTLS12}),
		kfake.SeedTopics(1, "tls-events"),
	)
	if err != nil {
		t.Fatal(err)
	}
	defer cluster.Close()

	clientTLS, err := LoadTLSConfig(true, wrongCAPath, "", "")
	if err != nil {
		t.Fatal(err)
	}
	producer, err := NewProducerTLS(cluster.ListenAddrs(), clientTLS)
	if err != nil {
		t.Fatal(err)
	}
	defer producer.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	if err := producer.Publish(ctx, "tls-events", [][]byte{[]byte("must-fail")}); err == nil {
		t.Fatal("expected an untrusted broker certificate to fail")
	}
}
