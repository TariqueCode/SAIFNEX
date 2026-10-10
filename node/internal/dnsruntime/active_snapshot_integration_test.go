package dnsruntime

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/TariqueCode/SAIFNEX/node/internal/agent"
	"github.com/TariqueCode/SAIFNEX/node/internal/dnsfilter"
)

// writeIntegrationActiveSnapshot creates the same persisted envelope consumed
// by the node agent, using a canonical Laravel-compatible snapshot.
func writeIntegrationActiveSnapshot(t *testing.T, tamperSignature bool) (string, agent.Settings) {
	t.Helper()
	publicKey, privateKey, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}

	snapshot := json.RawMessage(`{"devices":{"device-1":{"rules":[{"action":"BLOCK","enabled":true,"id":1,"priority":1,"target":"blocked.example","target_type":"DOMAIN_SUFFIX"}]}},"network":{"id":"42","timezone":"Asia/Dhaka"},"schema_version":1}`)
	hash := sha256.Sum256(snapshot)
	signature := ed25519.Sign(privateKey, snapshot)
	if tamperSignature {
		signature[0] ^= 0xff
	}

	active := map[string]any{
		"deployment_id": int64(7),
		"configuration_id": int64(9),
		"version": 3,
		"schema_version": 1,
		"snapshot_hash": hex.EncodeToString(hash[:]),
		"signature": base64.StdEncoding.EncodeToString(signature),
		"signature_algorithm": "Ed25519",
		"snapshot": snapshot,
	}
	data, err := json.Marshal(active)
	if err != nil {
		t.Fatal(err)
	}
	stateDir := t.TempDir()
	if err := os.WriteFile(filepath.Join(stateDir, "active-config.json"), data, 0600); err != nil {
		t.Fatal(err)
	}
	settings := agent.Settings{
		NetworkID: "42",
		PublicKey: base64.StdEncoding.EncodeToString(publicKey),
	}
	return stateDir, settings
}

func TestHandlerUsesVerifiedPersistedSnapshotEndToEnd(t *testing.T) {
	stateDir, settings := writeIntegrationActiveSnapshot(t, false)
	handler, err := NewHandler(Config{
		DeviceID: "device-1",
		Upstream: "127.0.0.1:9",
		DefaultAction: dnsfilter.Allow,
		Timeout: time.Second,
		Snapshot: func(context.Context) ([]byte, error) {
			return agent.LoadActiveSnapshot(stateDir, settings)
		},
	})
	if err != nil {
		t.Fatal(err)
	}

	listener := startDNSServer(t, handler)
	response := query(t, listener, "ads.blocked.example")
	if response.Rcode != 3 { // NXDOMAIN
		t.Fatalf("rcode = %d, want NXDOMAIN from verified BLOCK rule", response.Rcode)
	}
}

func TestHandlerFailsClosedWhenPersistedSnapshotSignatureIsTampered(t *testing.T) {
	stateDir, settings := writeIntegrationActiveSnapshot(t, true)
	handler, err := NewHandler(Config{
		DeviceID: "device-1",
		Upstream: "127.0.0.1:9",
		DefaultAction: dnsfilter.Allow,
		Timeout: time.Second,
		Snapshot: func(context.Context) ([]byte, error) {
			return agent.LoadActiveSnapshot(stateDir, settings)
		},
	})
	if err != nil {
		t.Fatal(err)
	}

	listener := startDNSServer(t, handler)
	response := query(t, listener, "ads.blocked.example")
	if response.Rcode != 2 { // SERVFAIL
		t.Fatalf("rcode = %d, want SERVFAIL when signature verification fails", response.Rcode)
	}
}
