package dnsfilter

import (
	"errors"
	"testing"
	"time"
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

func TestEvaluateSnapshotDefaultsOmittedEnabledToTrue(t *testing.T) {
	snapshot := []byte(`{"schema_version":1,"devices":{"7":{"rules":[
		{"id":1,"target_type":"DOMAIN","target":"example.com","action":"BLOCK","priority":1}
	]}}}`)
	decision, err := EvaluateSnapshot(snapshot, "7", "example.com", Allow)
	if err != nil {
		t.Fatalf("EvaluateSnapshot() error = %v", err)
	}
	if decision.Action != Block || !decision.Matched || decision.RuleID != 1 {
		t.Fatalf("decision = %+v, want omitted enabled field to default to enabled BLOCK rule", decision)
	}
}

func TestEvaluateSnapshotHonorsExplicitDisabledRule(t *testing.T) {
	snapshot := []byte(`{"schema_version":1,"devices":{"7":{"rules":[
		{"id":1,"target_type":"DOMAIN","target":"example.com","action":"BLOCK","priority":1,"enabled":false}
	]}}}`)
	decision, err := EvaluateSnapshot(snapshot, "7", "example.com", Allow)
	if err != nil {
		t.Fatalf("EvaluateSnapshot() error = %v", err)
	}
	if decision.Action != Allow || decision.Matched {
		t.Fatalf("decision = %+v, want default ALLOW for explicitly disabled rule", decision)
	}
}

func TestEvaluateSnapshotRejectsInvalidEnabledField(t *testing.T) {
	snapshot := []byte(`{"schema_version":1,"devices":{"7":{"rules":[
		{"id":1,"target_type":"DOMAIN","target":"example.com","action":"BLOCK","priority":1,"enabled":"yes"}
	]}}}`)
	if _, err := EvaluateSnapshot(snapshot, "7", "example.com", Allow); err == nil {
		t.Fatal("expected non-boolean enabled field to be rejected")
	}
}

func TestEvaluateSnapshotRejectsMissingDevice(t *testing.T) {
	_, err := EvaluateSnapshot([]byte(`{"schema_version":1,"devices":{"7":{"rules":[]}}}`), "9", "example.com", Allow)
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
	_, err := EvaluateSnapshot([]byte(`{"schema_version":1,"devices":{"7":{"rules":[]}}}`), "7", "example.com", Action("WARN"))
	if !errors.Is(err, ErrUnsupportedRule) {
		t.Fatalf("error = %v, want ErrUnsupportedRule", err)
	}
}

func TestEvaluateSnapshotBreaksEqualPriorityByRuleID(t *testing.T) {
	snapshot := []byte(`{"schema_version":1,"devices":{"7":{"rules":[
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
	_, err := EvaluateSnapshot([]byte(`{"schema_version":1,"devices":{"7":{"name":"Laptop"}}}`), "7", "example.com", Block)
	if err == nil {
		t.Fatal("expected missing rules list to fail closed")
	}
}

func TestEvaluateSnapshotRejectsMissingSchemaVersion(t *testing.T) {
	_, err := EvaluateSnapshot([]byte(`{"devices":{"7":{"rules":[]}}}`), "7", "example.com", Allow)
	if err == nil {
		t.Fatal("expected missing schema_version to fail")
	}
}

func TestEvaluateSnapshotRejectsUnsupportedSchemaVersion(t *testing.T) {
	_, err := EvaluateSnapshot([]byte(`{"schema_version":2,"devices":{"7":{"rules":[]}}}`), "7", "example.com", Allow)
	if err == nil {
		t.Fatal("expected unsupported schema_version to fail")
	}
}


func TestEvaluateSnapshotEnforcesScheduleAndExpiryAtRuntime(t *testing.T) {
	snapshot := []byte(`{
		"schema_version": 1,
		"devices": {
			"7": {
				"rules": [
					{"id": 1, "target_type": "DOMAIN", "target": "scheduled.example", "action": "BLOCK", "priority": 1, "enabled": true, "schedule_id": 12},
					{"id": 2, "target_type": "DOMAIN", "target": "temporary.example", "action": "BLOCK", "priority": 2, "enabled": true, "expires_at": "2026-10-08T07:00:00Z"}
				],
				"schedules": {
					"12": {
						"timezone": "Asia/Dhaka",
						"enabled": true,
						"definition": {"days": [4], "start": "09:00", "end": "17:00"}
					}
				}
			}
		}
	}`)

	atNoonDhaka := time.Date(2026, 10, 8, 6, 0, 0, 0, time.UTC)
	decision, err := EvaluateSnapshotAt(snapshot, "7", "scheduled.example", Allow, atNoonDhaka)
	if err != nil || decision.Action != Block || !decision.Matched {
		t.Fatalf("scheduled rule at noon Dhaka = %+v, %v; want BLOCK", decision, err)
	}
	decision, err = EvaluateSnapshotAt(snapshot, "7", "temporary.example", Allow, atNoonDhaka)
	if err != nil || decision.Action != Block || !decision.Matched {
		t.Fatalf("temporary rule before expiry = %+v, %v; want BLOCK", decision, err)
	}

	afterExpiry := time.Date(2026, 10, 8, 7, 0, 0, 0, time.UTC)
	decision, err = EvaluateSnapshotAt(snapshot, "7", "temporary.example", Allow, afterExpiry)
	if err != nil || decision.Action != Allow || decision.Matched {
		t.Fatalf("temporary rule at expiry = %+v, %v; want default ALLOW", decision, err)
	}

	afterSchedule := time.Date(2026, 10, 8, 12, 0, 0, 0, time.UTC)
	decision, err = EvaluateSnapshotAt(snapshot, "7", "scheduled.example", Allow, afterSchedule)
	if err != nil || decision.Action != Allow || decision.Matched {
		t.Fatalf("scheduled rule outside window = %+v, %v; want default ALLOW", decision, err)
	}
}


func TestEvaluateSnapshotFailsClosedWhenReferencedScheduleIsMissing(t *testing.T) {
	snapshot := []byte(`{"schema_version":1,"devices":{"7":{"rules":[
		{"id":1,"target_type":"DOMAIN","target":"example.com","action":"BLOCK","priority":1,"enabled":true,"schedule_id":999}
	],"schedules":{}}}}`)
	_, err := EvaluateSnapshotAt(
		snapshot,
		"7",
		"example.com",
		Allow,
		time.Date(2026, 10, 8, 6, 0, 0, 0, time.UTC),
	)
	if err == nil {
		t.Fatal("expected missing referenced schedule to fail closed")
	}
}


func TestEvaluateSnapshotSupportsOvernightScheduleAcrossSundayMonday(t *testing.T) {
	snapshot := []byte(`{
		"schema_version": 1,
		"devices": {
			"7": {
				"rules": [
					{"id": 1, "target_type": "DOMAIN", "target": "overnight.example", "action": "BLOCK", "priority": 1, "enabled": true, "schedule_id": 5}
				],
				"schedules": {
					"5": {
						"timezone": "Asia/Dhaka",
						"enabled": true,
						"definition": {"days": [7], "start": "22:00", "end": "06:00"}
					}
				}
			}
		}
	}`)
	// Monday 02:00 in Dhaka is Sunday 20:00 UTC.
	mondayAtTwo := time.Date(2026, 10, 11, 20, 0, 0, 0, time.UTC)
	decision, err := EvaluateSnapshotAt(snapshot, "7", "overnight.example", Allow, mondayAtTwo)
	if err != nil || decision.Action != Block || !decision.Matched {
		t.Fatalf("overnight rule Monday 02:00 Dhaka = %+v, %v; want BLOCK", decision, err)
	}
	// Monday 07:00 in Dhaka is outside the overnight window.
	mondayAtSeven := time.Date(2026, 10, 12, 1, 0, 0, 0, time.UTC)
	decision, err = EvaluateSnapshotAt(snapshot, "7", "overnight.example", Allow, mondayAtSeven)
	if err != nil || decision.Action != Allow || decision.Matched {
		t.Fatalf("overnight rule Monday 07:00 Dhaka = %+v, %v; want default ALLOW", decision, err)
	}
}


func TestEvaluateSnapshotEnforcesStartsAtBoundary(t *testing.T) {
	snapshot := []byte(`{"schema_version":1,"devices":{"7":{"rules":[
		{"id":1,"target_type":"DOMAIN","target":"future.example","action":"BLOCK","priority":1,"enabled":true,"starts_at":"2026-10-08T07:00:00Z"}
	],"schedules":{}}}}`)
	before := time.Date(2026, 10, 8, 6, 59, 59, 0, time.UTC)
	decision, err := EvaluateSnapshotAt(snapshot, "7", "future.example", Allow, before)
	if err != nil || decision.Action != Allow || decision.Matched {
		t.Fatalf("decision before starts_at = %+v, %v; want default ALLOW", decision, err)
	}
	atBoundary := time.Date(2026, 10, 8, 7, 0, 0, 0, time.UTC)
	decision, err = EvaluateSnapshotAt(snapshot, "7", "future.example", Allow, atBoundary)
	if err != nil || decision.Action != Block || !decision.Matched {
		t.Fatalf("decision at starts_at = %+v, %v; want BLOCK", decision, err)
	}
}

func TestEvaluateSnapshotRejectsMalformedExpiryMetadata(t *testing.T) {
	snapshot := []byte(`{"schema_version":1,"devices":{"7":{"rules":[
		{"id":1,"target_type":"DOMAIN","target":"broken.example","action":"BLOCK","priority":1,"enabled":true,"expires_at":"not-a-time"}
	],"schedules":{}}}}`)
	_, err := EvaluateSnapshotAt(
		snapshot,
		"7",
		"broken.example",
		Allow,
		time.Date(2026, 10, 8, 6, 0, 0, 0, time.UTC),
	)
	if err == nil {
		t.Fatal("expected malformed expiry metadata to fail closed")
	}
}
