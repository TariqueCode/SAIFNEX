package dnsfilter

import (
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"strings"
	"strconv"
	"time"
)

// Action is the DNS decision produced by an explicitly supported domain rule.
type Action string

const (
	Allow Action = "ALLOW"
	Block Action = "BLOCK"
)

// Rule is the minimal, runtime-facing subset of a published SAIFNEX policy rule.
// Rules must be passed in the control plane's priority order (lowest number first).
type Rule struct {
	ID         int64  `json:"id"`
	TargetType string `json:"target_type"`
	Target     string `json:"target"`
	Priority   int64  `json:"priority"`
	Action     string `json:"action"`
	Enabled    bool   `json:"enabled"`
	ScheduleID int64  `json:"schedule_id"`
	StartsAt   string `json:"starts_at"`
	ExpiresAt  string `json:"expires_at"`
}

// UnmarshalJSON preserves the control-plane convention that rules are enabled
// unless explicitly disabled. A plain bool would silently turn an omitted
// "enabled" field into false and cause Laravel snapshots to behave differently
// from the policy compiler.
func (r *Rule) UnmarshalJSON(data []byte) error {
	type ruleAlias Rule
	var decoded ruleAlias
	if err := json.Unmarshal(data, &decoded); err != nil {
		return err
	}
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(data, &fields); err != nil {
		return err
	}
	if _, exists := fields["enabled"]; !exists {
		decoded.Enabled = true
	}
	*r = Rule(decoded)
	return nil
}

// Decision describes the first matching rule, or the configured default action.
type Decision struct {
	Action   Action
	Matched  bool
	RuleID   int64
	Domain   string
}

var (
	ErrInvalidDomain = errors.New("invalid DNS domain")
	ErrUnsupportedRule = errors.New("unsupported DNS rule")
)

// NormalizeDomain canonicalizes a DNS hostname for comparison. It intentionally
// rejects IP literals and malformed labels; callers must not treat an invalid
// query name as an allow decision.
func NormalizeDomain(value string) (string, error) {
	domain := strings.TrimSuffix(strings.ToLower(strings.TrimSpace(value)), ".")
	if domain == "" || len(domain) > 253 || net.ParseIP(domain) != nil {
		return "", ErrInvalidDomain
	}

	for _, label := range strings.Split(domain, ".") {
		if len(label) == 0 || len(label) > 63 || label[0] == '-' || label[len(label)-1] == '-' {
			return "", ErrInvalidDomain
		}
		for _, r := range label {
			if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') || r == '-' {
				continue
			}
			return "", ErrInvalidDomain
		}
	}
	return domain, nil
}

// Evaluate applies the first matching enabled DOMAIN_EXACT or DOMAIN_SUFFIX
// rule. Unsupported enabled rule types/actions return an error instead of being
// silently interpreted. If no rule matches, defaultAction is returned.
func Evaluate(query string, rules []Rule, defaultAction Action) (Decision, error) {
	return EvaluateAt(query, rules, defaultAction, time.Now().UTC(), nil)
}

// EvaluateAt evaluates rule time bounds and schedules against one explicit instant.
// Schedule definitions are keyed by their numeric schedule ID string.
func EvaluateAt(query string, rules []Rule, defaultAction Action, at time.Time, schedules map[string]Schedule) (Decision, error) {
	domain, err := NormalizeDomain(query)
	if err != nil {
		return Decision{}, err
	}
	if defaultAction != Allow && defaultAction != Block {
		return Decision{}, fmt.Errorf("%w: default action %q", ErrUnsupportedRule, defaultAction)
	}

	for _, rule := range rules {
		if !rule.Enabled {
			continue
		}

		active, timeErr := ruleTimeActive(rule, at, schedules)
		if timeErr != nil {
			return Decision{}, fmt.Errorf("%w: invalid time metadata on rule %d: %v", ErrUnsupportedRule, rule.ID, timeErr)
		}
		if !active {
			continue
		}

		// Rules for non-domain targets belong to other policy evaluators and
		// cannot match a DNS hostname. Ignore the known target families rather
		// than making every DNS query fail when a device also has IP/CIDR rules.
		switch rule.TargetType {
		case "DOMAIN", "DOMAIN_EXACT", "DOMAIN_SUFFIX":
			// Evaluated below.
		case "IP", "CIDR", "KEYWORD":
			continue
		default:
			return Decision{}, fmt.Errorf("%w: target type %q on rule %d", ErrUnsupportedRule, rule.TargetType, rule.ID)
		}

		target, err := NormalizeDomain(rule.Target)
		if err != nil {
			return Decision{}, fmt.Errorf("%w: target on rule %d", ErrUnsupportedRule, rule.ID)
		}

		matched := false
		switch rule.TargetType {
		case "DOMAIN", "DOMAIN_EXACT":
			// Laravel's policy model calls exact domain rules DOMAIN. Keep
			// DOMAIN_EXACT as an explicit runtime alias for older snapshots.
			matched = domain == target
		case "DOMAIN_SUFFIX":
			matched = domain == target || strings.HasSuffix(domain, "."+target)
		default:
			return Decision{}, fmt.Errorf("%w: target type %q on rule %d", ErrUnsupportedRule, rule.TargetType, rule.ID)
		}
		if matched {
			action := Action(rule.Action)
			if action != Allow && action != Block {
				return Decision{}, fmt.Errorf("%w: action %q on rule %d", ErrUnsupportedRule, rule.Action, rule.ID)
			}
			return Decision{Action: action, Matched: true, RuleID: rule.ID, Domain: domain}, nil
		}
	}
	return Decision{Action: defaultAction, Matched: false, Domain: domain}, nil
}


// Schedule mirrors the signed control-plane schedule subset consumed by DNS.
type Schedule struct {
	Timezone   string         `json:"timezone"`
	Definition ScheduleWindow `json:"definition"`
	Enabled    bool           `json:"enabled"`
}

type ScheduleWindow struct {
	Days  []int  `json:"days"`
	Start string `json:"start"`
	End   string `json:"end"`
}

func ruleTimeActive(rule Rule, at time.Time, schedules map[string]Schedule) (bool, error) {
	if rule.StartsAt != "" {
		start, err := time.Parse(time.RFC3339, rule.StartsAt)
		if err != nil {
			return false, fmt.Errorf("invalid starts_at: %w", err)
		}
		if at.Before(start) {
			return false, nil
		}
	}
	if rule.ExpiresAt != "" {
		expires, err := time.Parse(time.RFC3339, rule.ExpiresAt)
		if err != nil {
			return false, fmt.Errorf("invalid expires_at: %w", err)
		}
		if !at.Before(expires) {
			return false, nil
		}
	}
	if rule.ScheduleID == 0 {
		return true, nil
	}
	schedule, ok := schedules[strconv.FormatInt(rule.ScheduleID, 10)]
	if !ok {
		return false, fmt.Errorf("schedule %d is missing from signed device snapshot", rule.ScheduleID)
	}
	if !schedule.Enabled {
		return false, nil
	}
	location, err := time.LoadLocation(schedule.Timezone)
	if err != nil {
		return false, fmt.Errorf("invalid timezone %q: %w", schedule.Timezone, err)
	}
	local := at.In(location)
	start, errStart := parseScheduleTime(schedule.Definition.Start)
	end, errEnd := parseScheduleTime(schedule.Definition.End)
	if errStart != nil || errEnd != nil || len(schedule.Definition.Days) == 0 {
		return false, errors.New("invalid schedule definition")
	}
	for _, day := range schedule.Definition.Days {
		if day < 1 || day > 7 {
			return false, fmt.Errorf("invalid ISO weekday %d", day)
		}
	}
	weekday := int(local.Weekday())
	if weekday == 0 {
		weekday = 7
	}
	current := local.Hour()*3600 + local.Minute()*60 + local.Second()
	containsDay := func(day int) bool {
		for _, candidate := range schedule.Definition.Days {
			if candidate == day {
				return true
			}
		}
		return false
	}
	if start == end {
		return containsDay(weekday), nil
	}
	if start < end {
		return containsDay(weekday) && current >= start && current <= end, nil
	}
	if current >= start {
		return containsDay(weekday), nil
	}
	previous := weekday - 1
	if previous == 0 {
		previous = 7
	}
	return current <= end && containsDay(previous), nil
}

func parseScheduleTime(value string) (int, error) {
	parsed, err := time.Parse("15:04", value)
	if err != nil {
		parsed, err = time.Parse("15:04:05", value)
	}
	if err != nil {
		return 0, err
	}
	return parsed.Hour()*3600 + parsed.Minute()*60 + parsed.Second(), nil
}
