package agent

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
)

func TestFailedConfigurationUpdatePreservesPreviousActiveSnapshot(t *testing.T) {
	stateDir := t.TempDir()
	settings := writeSignedActiveConfig(t, stateDir, false)
	activePath := filepath.Join(stateDir, "active-config.json")
	before, err := os.ReadFile(activePath)
	if err != nil {
		t.Fatal(err)
	}

	// The next deployment has a deliberately invalid signature. The agent must
	// report FAILED and leave the last verified active snapshot untouched.
	badConfig := configuration{
		DeploymentID:       8,
		ConfigurationID:    10,
		Version:            4,
		SchemaVersion:      1,
		Snapshot:           json.RawMessage(`{"schema_version":1,"network":{"id":"42","timezone":"Asia/Dhaka"},"devices":{}}`),
		SnapshotHash:       "",
		Signature:          "not-a-valid-signature",
		SignatureAlgorithm: "Ed25519",
	}

	canonical, err := canonicalJSON(badConfig.Snapshot)
	if err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256(canonical)
	badConfig.SnapshotHash = hex.EncodeToString(sum[:])

	var ackStatus string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch {
		case r.URL.Path == "/api/internal/v1/nodes/node-1/heartbeat" && r.Method == http.MethodPost:
			_, _ = w.Write([]byte(`{"success":true,"data":null}`))
		case r.URL.Path == "/api/internal/v1/nodes/node-1/configuration" && r.Method == http.MethodGet:
			_ = json.NewEncoder(w).Encode(map[string]any{"success": true, "data": badConfig})
		case r.URL.Path == "/api/internal/v1/nodes/node-1/deployments/8/ack" && r.Method == http.MethodPost:
			var ack ackPayload
			if err := json.NewDecoder(r.Body).Decode(&ack); err != nil {
				t.Errorf("decode acknowledgement: %v", err)
			}
			ackStatus = ack.Status
			_, _ = w.Write([]byte(`{"success":true,"data":null}`))
		default:
			http.NotFound(w, r)
		}
	}))
	defer server.Close()

	settings.ControlPlaneURL = server.URL
	settings.NodeID = "node-1"
	settings.BearerToken = "test-token"
	settings.StateDir = stateDir
	settings.HTTPTimeout = "2s"
	settings.PollInterval = "1s"

	client, err := NewClient(settings)
	if err != nil {
		t.Fatal(err)
	}
	if err := client.cycle(context.Background()); err == nil {
		t.Fatal("expected invalid configuration deployment to fail")
	}
	if ackStatus != "FAILED" {
		t.Fatalf("ack status = %q, want FAILED", ackStatus)
	}

	after, err := os.ReadFile(activePath)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(before, after) {
		t.Fatal("failed configuration update changed the previously active snapshot")
	}
}

func TestActiveVersionRequiresVerifiedPersistedSnapshot(t *testing.T) {
	stateDir := t.TempDir()
	settings := writeSignedActiveConfig(t, stateDir, false)
	client := &Client{settings: settings}
	if got := client.activeVersion(); got != 3 {
		t.Fatalf("activeVersion() = %d, want 3 for a verified snapshot", got)
	}

	// Damage the persisted signature while keeping the envelope parseable.
	path := filepath.Join(stateDir, "active-config.json")
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	data = bytes.Replace(data, []byte("signature"), []byte("signature"), 1)
	var envelope map[string]any
	if err := json.Unmarshal(data, &envelope); err != nil {
		t.Fatal(err)
	}
	envelope["signature"] = "invalid-signature"
	data, err = json.Marshal(envelope)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, data, 0600); err != nil {
		t.Fatal(err)
	}
	if got := client.activeVersion(); got != 0 {
		t.Fatalf("activeVersion() = %d, want 0 for an unverified snapshot", got)
	}
}
