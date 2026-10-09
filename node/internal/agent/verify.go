package agent

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
)

func verifyConfiguration(c configuration, settings Settings) ([]byte, error) {
	if c.DeploymentID <= 0 || c.Version <= 0 || c.SchemaVersion <= 0 {
		return nil, errors.New("configuration envelope contains invalid identifiers or versions")
	}
	if c.SignatureAlgorithm != "Ed25519" {
		return nil, fmt.Errorf("unsupported signature algorithm %q", c.SignatureAlgorithm)
	}
	canonical, err := canonicalJSON(c.Snapshot)
	if err != nil { return nil, err }

	sum := sha256.Sum256(canonical)
	actualHash := hex.EncodeToString(sum[:])
	if len(c.SnapshotHash) != 64 || actualHash != c.SnapshotHash {
		return nil, errors.New("snapshot SHA-256 hash mismatch")
	}
	pub, err := base64.StdEncoding.DecodeString(settings.PublicKey)
	if err != nil || len(pub) != ed25519.PublicKeySize {
		return nil, errors.New("invalid Ed25519 public key")
	}
	sig, err := base64.StdEncoding.DecodeString(c.Signature)
	if err != nil || len(sig) != ed25519.SignatureSize {
		return nil, errors.New("invalid Ed25519 signature encoding")
	}
	if !ed25519.Verify(ed25519.PublicKey(pub), canonical, sig) {
		return nil, errors.New("Ed25519 signature verification failed")
	}

	var snapshot map[string]any
	if err := json.Unmarshal(c.Snapshot, &snapshot); err != nil || snapshot == nil {
		return nil, errors.New("snapshot must be a JSON object")
	}
	// Check the network binding when the snapshot carries it. Older snapshots
	// without this field remain compatible; deployment ownership is enforced by
	// the authenticated control-plane endpoint.
	if networkID, ok := snapshot["network_id"]; ok {
		if fmt.Sprint(networkID) != settings.NetworkID {
			return nil, errors.New("snapshot network_id does not match this node")
		}
	}
	return canonical, nil
}
