package agent

import "encoding/json"

type envelope[T any] struct {
	Success bool `json:"success"`
	Data    T    `json:"data"`
	Error   *struct {
		Code    string `json:"code"`
		Message string `json:"message"`
	} `json:"error,omitempty"`
}

type heartbeatPayload struct {
	Version      string         `json:"version"`
	ConfigVersion int           `json:"config_version"`
	Capabilities map[string]any `json:"capabilities"`
}

type configuration struct {
	DeploymentID       int64           `json:"deployment_id"`
	ConfigurationID    int64           `json:"configuration_id"`
	Version            int             `json:"version"`
	SchemaVersion      int             `json:"schema_version"`
	Snapshot           json.RawMessage `json:"snapshot"`
	SnapshotHash       string          `json:"snapshot_hash"`
	Signature          string          `json:"signature"`
	SignatureAlgorithm string          `json:"signature_algorithm"`
}

type ackPayload struct {
	Status        string `json:"status"`
	ConfigVersion int    `json:"config_version"`
	SnapshotHash  string `json:"snapshot_hash"`
	ErrorMessage  string `json:"error_message,omitempty"`
}
