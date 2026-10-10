package dnsfilter

import (
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"time"
)

const supportedSnapshotSchemaVersion = 1

// ErrDeviceNotFound indicates that a verified configuration has no policy entry
// for the requested device. Callers must not silently substitute another device.
var ErrDeviceNotFound = errors.New("device not found in configuration snapshot")

type snapshotDocument struct {
	SchemaVersion *int `json:"schema_version"`
	Devices       map[string]struct {
		Rules *[]Rule `json:"rules"`
		Schedules map[string]Schedule `json:"schedules"`
	} `json:"devices"`
}

// EvaluateSnapshot evaluates the policy for one device from a verified SAIFNEX
// configuration snapshot. Signature/hash verification remains the node agent's
// responsibility and must happen before this function is called.
//
// The evaluator accepts only the explicitly supported snapshot schema version.
// Rules are sorted by ascending priority and then ascending ID to make evaluation
// deterministic. A missing schema version is not treated as the current version.
func EvaluateSnapshot(snapshot []byte, deviceID string, query string, defaultAction Action) (Decision, error) {
	return EvaluateSnapshotAt(snapshot, deviceID, query, defaultAction, time.Now().UTC())
}

// EvaluateSnapshotAt evaluates a signed snapshot at an explicit instant, useful
// for deterministic tests and schedule boundary verification.
func EvaluateSnapshotAt(snapshot []byte, deviceID string, query string, defaultAction Action, at time.Time) (Decision, error) {
	if deviceID == "" {
		return Decision{}, fmt.Errorf("%w: empty device id", ErrDeviceNotFound)
	}

	var document snapshotDocument
	if err := json.Unmarshal(snapshot, &document); err != nil {
		return Decision{}, fmt.Errorf("decode configuration snapshot: %w", err)
	}
	if document.SchemaVersion == nil {
		return Decision{}, errors.New("configuration snapshot has no schema_version")
	}
	if *document.SchemaVersion != supportedSnapshotSchemaVersion {
		return Decision{}, fmt.Errorf("unsupported configuration snapshot schema_version %d", *document.SchemaVersion)
	}
	if document.Devices == nil {
		return Decision{}, errors.New("configuration snapshot has no devices map")
	}

	device, ok := document.Devices[deviceID]
	if !ok {
		return Decision{}, fmt.Errorf("%w: %s", ErrDeviceNotFound, deviceID)
	}

	if device.Rules == nil {
		return Decision{}, fmt.Errorf("device %s has no rules list in configuration snapshot", deviceID)
	}

	rules := append([]Rule(nil), (*device.Rules)...)
	sort.SliceStable(rules, func(i, j int) bool {
		if rules[i].Priority == rules[j].Priority {
			return rules[i].ID < rules[j].ID
		}
		return rules[i].Priority < rules[j].Priority
	})

	return EvaluateAt(query, rules, defaultAction, at, device.Schedules)
}
