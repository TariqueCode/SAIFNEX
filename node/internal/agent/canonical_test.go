package agent

import (
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"testing"
)

func TestCanonicalJSONSortsNestedObjectsButPreservesArrays(t *testing.T) {
	got, err := canonicalJSON(json.RawMessage(`{"z":1,"a":{"y":2,"b":3},"items":[{"z":1,"a":2},4]}`))
	if err != nil { t.Fatal(err) }
	want := `{"a":{"b":3,"y":2},"items":[{"a":2,"z":1},4],"z":1}`
	if string(got) != want { t.Fatalf("canonical JSON mismatch\n got: %s\nwant: %s", got, want) }
}

func TestVerifyConfigurationAcceptsValidEnvelope(t *testing.T) {
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil { t.Fatal(err) }
	snapshot := json.RawMessage(`{"network_id":"net-1","rules":[{"action":"BLOCK","domain":"example.com"}]}`)
	canonical, err := canonicalJSON(snapshot)
	if err != nil { t.Fatal(err) }
	hash := sha256.Sum256(canonical)
	hashText := hex.EncodeToString(hash[:])
	cfg := configuration{
		DeploymentID: 7, ConfigurationID: 3, Version: 2, SchemaVersion: 1,
		Snapshot: snapshot, SnapshotHash: hashText, SignatureAlgorithm: "Ed25519",
		Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(priv, canonical)),
	}
	settings := Settings{NetworkID: "net-1", PublicKey: base64.StdEncoding.EncodeToString(pub)}
	if _, err := verifyConfiguration(cfg, settings); err != nil { t.Fatalf("expected valid config, got %v", err) }
}

func TestVerifyConfigurationRejectsSignatureTampering(t *testing.T) {
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil { t.Fatal(err) }
	snapshot := json.RawMessage(`{"network_id":"net-1"}`)
	canonical, _ := canonicalJSON(snapshot)
	hash := sha256.Sum256(canonical)
	cfg := configuration{DeploymentID: 1, Version: 1, SchemaVersion: 1, Snapshot: snapshot, SnapshotHash: hex.EncodeToString(hash[:]), SignatureAlgorithm: "Ed25519", Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(priv, []byte("different")))}
	settings := Settings{NetworkID: "net-1", PublicKey: base64.StdEncoding.EncodeToString(pub)}
	if _, err := verifyConfiguration(cfg, settings); err == nil { t.Fatal("expected signature verification failure") }
}

func TestVerifyConfigurationRejectsWrongNetwork(t *testing.T) {
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	snapshot := json.RawMessage(`{"network_id":"another-network"}`)
	canonical, _ := canonicalJSON(snapshot)
	hash := sha256.Sum256(canonical)
	cfg := configuration{DeploymentID: 1, Version: 1, SchemaVersion: 1, Snapshot: snapshot, SnapshotHash: hex.EncodeToString(hash[:]), SignatureAlgorithm: "Ed25519", Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(priv, canonical))}
	settings := Settings{NetworkID: "net-1", PublicKey: base64.StdEncoding.EncodeToString(pub)}
	if _, err := verifyConfiguration(cfg, settings); err == nil { t.Fatal("expected network mismatch") }
}
