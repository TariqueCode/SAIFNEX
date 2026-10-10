package agent

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func writeSignedActiveConfig(t *testing.T, stateDir string, tamper bool) Settings {
	t.Helper()
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	snapshot := json.RawMessage(`{"schema_version":1,"network":{"id":"42","timezone":"Asia/Dhaka"},"devices":{"device-1":{"rules":[]}}}`)
	canonical, err := canonicalJSON(snapshot)
	if err != nil {
		t.Fatal(err)
	}
	hash := sha256.Sum256(canonical)
	signature := ed25519.Sign(priv, canonical)
	if tamper {
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
		"snapshot": json.RawMessage(snapshot),
	}
	data, err := json.Marshal(active)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(stateDir, 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(stateDir, "active-config.json"), data, 0600); err != nil {
		t.Fatal(err)
	}
	return Settings{NetworkID: "42", PublicKey: base64.StdEncoding.EncodeToString(pub)}
}

func TestLoadActiveSnapshotReverifiesPersistedSignature(t *testing.T) {
	stateDir := t.TempDir()
	settings := writeSignedActiveConfig(t, stateDir, false)
	snapshot, err := LoadActiveSnapshot(stateDir, settings)
	if err != nil {
		t.Fatalf("expected valid active snapshot, got %v", err)
	}
	if len(snapshot) == 0 {
		t.Fatal("expected canonical snapshot bytes")
	}
}

func TestLoadActiveSnapshotRejectsTamperedSignature(t *testing.T) {
	stateDir := t.TempDir()
	settings := writeSignedActiveConfig(t, stateDir, true)
	if _, err := LoadActiveSnapshot(stateDir, settings); err == nil {
		t.Fatal("expected tampered active snapshot signature to be rejected")
	}
}


func TestLoadActiveSnapshotRejectsSnapshotHashMismatch(t *testing.T) {
	stateDir := t.TempDir()
	settings := writeSignedActiveConfig(t, stateDir, false)
	path := filepath.Join(stateDir, "active-config.json")
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	tampered := bytes.Replace(data, []byte("Asia/Dhaka"), []byte("Asia/Kolkata"), 1)
	if bytes.Equal(tampered, data) {
		t.Fatal("test fixture did not contain expected timezone")
	}
	if err := os.WriteFile(path, tampered, 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := LoadActiveSnapshot(stateDir, settings); err == nil {
		t.Fatal("expected modified snapshot to be rejected for hash/signature mismatch")
	}
}

func TestLoadActiveSnapshotRejectsWrongNetworkBinding(t *testing.T) {
	stateDir := t.TempDir()
	settings := writeSignedActiveConfig(t, stateDir, false)
	settings.NetworkID = "43"
	if _, err := LoadActiveSnapshot(stateDir, settings); err == nil {
		t.Fatal("expected snapshot for another network to be rejected")
	}
}

func TestLoadActiveSnapshotRejectsMissingFile(t *testing.T) {
	if _, err := LoadActiveSnapshot(t.TempDir(), Settings{}); err == nil {
		t.Fatal("expected missing active configuration to be rejected")
	}
}
