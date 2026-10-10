package dnsfilter

import (
	"errors"
	"testing"
)

func TestEvaluateSnapshotUsesDeviceRulesAndPriority(t *testing.T) {
	snapshot := []byte(`{
		"schema_version": 1,
		"devices": {
			"7": {
				"rules": [
					{"id": 20, "target_type": "DOMAIN_SUFFIX", "target": "example.com", "action": "BLOCK", "priority": 50, "enabled": true},
					{"id": 10, "target_type": "DOMAIN_EXACT", "target": "safe.example.com", "action": "ALLOW", "priority": 10, "enabled": true}
				]
			},
			"8": {
				"rules": [
					{"id": 30, "target_type": "DOMAIN_EXACT", "target": "safe.example.com", "action": "BLOCK", "priority": 1, "enabled": true}
				]
			}
		}
	}`)

	decision, err := EvaluateSnapshot(snapshot, "7", "safe.example.com", Block)
	if err != nil {
		t.Fatalf("EvaluateSnapshot() error = %v", err)
	}
	if decision.Action != Allow || decision.RuleID != 10 || !decision.Matched {
		t.Fatalf("decision = %+v, want priority-10 ALLOW rule", decision)
	}

	other, err := EvaluateSnapshot(snapshot, "8", "safe.example.com", Allow)
	if err != nil {
		t.Fatalf("EvaluateSnapshot(other device) error = %v", err)
	}
	if other.Action != Block || other.RuleID != 30 {
		t.Fatalf("other device decision = %+v, want BLOCK rule 30", other)
	}
}

func TestEvaluateSnapshotRejectsMissingDevice(t *testing.T) {
	_, err := EvaluateSnapshot([]byte(`{"devices":{"7":{"rules":[]}}}`), "9", "example.com", Allow)
	if !errors.Is(err, ErrDeviceNotFound) {
		t.Fatalf("error = %v, want ErrDeviceNotFound", err)
	}
}

func TestEvaluateSnapshotRejectsMalformedSnapshot(t *testing.T) {
	_, err := EvaluateSnapshot([]byte(`{"devices":`), "7", "example.com", Allow)
	if err == nil {
		t.Fatal("expected malformed snapshot to fail")
	}
}

func TestEvaluateSnapshotRequiresDevicesMap(t *testing.T) {
	_, err := EvaluateSnapshot([]byte(`{"schema_version":1}`), "7", "example.com", Allow)
	if err == nil {
		t.Fatal("expected snapshot without devices map to fail")
	}
}

func TestEvaluateSnapshotRequiresExplicitValidDefault(t *testing.T) {
	_, err := EvaluateSnapshot([]byte(`{"devices":{"7":{"rules":[]}}}`), "7", "example.com", Action("WARN"))
	if !errors.Is(err, ErrUnsupportedRule) {
		t.Fatalf("error = %v, want ErrUnsupportedRule", err)
	}
}

func TestEvaluateSnapshotBreaksEqualPriorityByRuleID(t *testing.T) {
	snapshot := []byte(`{"devices":{"7":{"rules":[
		{"id": 20, "target_type":"DOMAIN_EXACT","target":"example.com","action":"BLOCK","priority":5,"enabled":true},
		{"id": 10, "target_type":"DOMAIN_EXACT","target":"example.com","action":"ALLOW","priority":5,"enabled":true}
	]}}}`)

	decision, err := EvaluateSnapshot(snapshot, "7", "example.com", Block)
	if err != nil {
		t.Fatalf("EvaluateSnapshot() error = %v", err)
	}
	if decision.Action != Allow || decision.RuleID != 10 {
		t.Fatalf("decision = %+v, want lower-ID rule 10 to win equal-priority tie", decision)
	}
}

func TestEvaluateSnapshotRejectsDeviceWithoutRulesList(t *testing.T) {
	_, err := EvaluateSnapshot([]byte(`{"devices":{"7":{"name":"Laptop"}}}`), "7", "example.com", Block)
	if err == nil {
		t.Fatal("expected missing rules list to fail closed")
	}
}
