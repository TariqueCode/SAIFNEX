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

const supportedConfigurationSchemaVersion = 1

func verifyConfiguration(c configuration, settings Settings) ([]byte, error) {
	if c.DeploymentID <= 0 || c.Version <= 0 || c.SchemaVersion <= 0 {
		return nil, errors.New("configuration envelope contains invalid identifiers or versions")
	}
	if c.SchemaVersion != supportedConfigurationSchemaVersion {
		return nil, fmt.Errorf("unsupported configuration schema_version %d", c.SchemaVersion)
	}
	if c.SignatureAlgorithm != "Ed25519" {
		return nil, fmt.Errorf("unsupported signature algorithm %q", c.SignatureAlgorithm)
	}
	canonical, err := canonicalJSON(c.Snapshot)
	if err != nil {
		return nil, err
	}

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

	var snapshot struct {
		SchemaVersion *int            `json:"schema_version"`
		NetworkID     json.RawMessage `json:"network_id"`
		Network       *struct {
			ID json.RawMessage `json:"id"`
		} `json:"network"`
	}
	if err := json.Unmarshal(c.Snapshot, &snapshot); err != nil {
		return nil, errors.New("snapshot must be a valid JSON object")
	}
	if snapshot.SchemaVersion == nil {
		return nil, errors.New("snapshot schema_version is missing")
	}
	if *snapshot.SchemaVersion != c.SchemaVersion {
		return nil, fmt.Errorf("snapshot schema_version %d does not match envelope schema_version %d", *snapshot.SchemaVersion, c.SchemaVersion)
	}

	// The Laravel compiler publishes network identity as network.id. Accept the
	// older network_id form for compatibility, but never activate a snapshot
	// whose network binding is absent or disagrees with this node's enrollment.
	var networkValue json.RawMessage
	if len(snapshot.NetworkID) > 0 && string(snapshot.NetworkID) != "null" {
		networkValue = snapshot.NetworkID
	}
	if snapshot.Network != nil && len(snapshot.Network.ID) > 0 && string(snapshot.Network.ID) != "null" {
		if len(networkValue) > 0 && !sameJSONScalar(networkValue, snapshot.Network.ID) {
			return nil, errors.New("snapshot network and network_id fields disagree")
		}
		networkValue = snapshot.Network.ID
	}
	if len(networkValue) == 0 {
		return nil, errors.New("snapshot network identity is missing")
	}
	var networkID any
	if err := json.Unmarshal(networkValue, &networkID); err != nil {
		return nil, errors.New("snapshot network identity is invalid")
	}
	if fmt.Sprint(networkID) != settings.NetworkID {
		return nil, errors.New("snapshot network identity does not match this node")
	}
	return canonical, nil
}

func sameJSONScalar(a, b json.RawMessage) bool {
	var av, bv any
	if json.Unmarshal(a, &av) != nil || json.Unmarshal(b, &bv) != nil {
		return false
	}
	return fmt.Sprint(av) == fmt.Sprint(bv)
}
