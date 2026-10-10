package agent

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
)

// LoadActiveSnapshot reloads the persisted configuration and re-verifies its
// hash, signature, schema, and network binding before exposing policy to a
// data-plane consumer. A missing or damaged file is never treated as allow-all.
func LoadActiveSnapshot(stateDir string, settings Settings) ([]byte, error) {
	path := filepath.Join(stateDir, "active-config.json")
	file, err := os.Open(path)
	if err != nil {
		return nil, fmt.Errorf("open active configuration: %w", err)
	}
	defer file.Close()

	data, err := io.ReadAll(io.LimitReader(file, (4<<20)+1))
	if err != nil {
		return nil, fmt.Errorf("read active configuration: %w", err)
	}
	if len(data) > 4<<20 {
		return nil, errors.New("active configuration exceeds 4 MiB limit")
	}
	var active struct {
		DeploymentID int64 `json:"deployment_id"`
		ConfigurationID int64 `json:"configuration_id"`
		Version int `json:"version"`
		SchemaVersion int `json:"schema_version"`
		Snapshot json.RawMessage `json:"snapshot"`
		SnapshotHash string `json:"snapshot_hash"`
		Signature string `json:"signature"`
		SignatureAlgorithm string `json:"signature_algorithm"`
	}
	if err := json.Unmarshal(data, &active); err != nil {
		return nil, fmt.Errorf("parse active configuration: %w", err)
	}
	if len(active.Snapshot) == 0 || string(active.Snapshot) == "null" {
		return nil, errors.New("active configuration snapshot is missing")
	}
	cfg := configuration{
		DeploymentID: active.DeploymentID,
		ConfigurationID: active.ConfigurationID,
		Version: active.Version,
		SchemaVersion: active.SchemaVersion,
		Snapshot: active.Snapshot,
		SnapshotHash: active.SnapshotHash,
		Signature: active.Signature,
		SignatureAlgorithm: active.SignatureAlgorithm,
	}
	canonical, err := verifyConfiguration(cfg, settings)
	if err != nil {
		return nil, fmt.Errorf("re-verify active configuration: %w", err)
	}
	return canonical, nil
}
