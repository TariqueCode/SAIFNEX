package dnsfilter

import (
	"encoding/json"
	"errors"
	"fmt"
	"sort"
)

// ErrDeviceNotFound indicates that a verified configuration has no policy entry
// for the requested device. Callers must not silently substitute another device.
var ErrDeviceNotFound = errors.New("device not found in configuration snapshot")

type snapshotDocument struct {
	Devices map[string]struct {
		Rules []Rule `json:"rules"`
	} `json:"devices"`
}

// EvaluateSnapshot evaluates the policy for one device from a verified SAIFNEX
// configuration snapshot. Signature/hash verification remains the node agent's
// responsibility and must happen before this function is called.
//
// The default action is explicit because the current control-plane snapshot
// schema does not define a universal DNS default. Rules are sorted by ascending
// priority and then ascending ID to make evaluation deterministic.
func EvaluateSnapshot(snapshot []byte, deviceID string, query string, defaultAction Action) (Decision, error) {
	if deviceID == "" {
		return Decision{}, fmt.Errorf("%w: empty device id", ErrDeviceNotFound)
	}

	var document snapshotDocument
	if err := json.Unmarshal(snapshot, &document); err != nil {
		return Decision{}, fmt.Errorf("decode configuration snapshot: %w", err)
	}
	if document.Devices == nil {
		return Decision{}, errors.New("configuration snapshot has no devices map")
	}

	device, ok := document.Devices[deviceID]
	if !ok {
		return Decision{}, fmt.Errorf("%w: %s", ErrDeviceNotFound, deviceID)
	}

	rules := append([]Rule(nil), device.Rules...)
	sort.SliceStable(rules, func(i, j int) bool {
		if rules[i].Priority == rules[j].Priority {
			return rules[i].ID < rules[j].ID
		}
		return rules[i].Priority < rules[j].Priority
	})

	return Evaluate(query, rules, defaultAction)
}
