package dnsfilter

import (
	"errors"
	"fmt"
	"net"
	"strings"
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
	Action     string `json:"action"`
	Enabled    bool   `json:"enabled"`
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
		action := Action(rule.Action)
		if action != Allow && action != Block {
			return Decision{}, fmt.Errorf("%w: action %q on rule %d", ErrUnsupportedRule, rule.Action, rule.ID)
		}
		target, err := NormalizeDomain(rule.Target)
		if err != nil {
			return Decision{}, fmt.Errorf("%w: target on rule %d", ErrUnsupportedRule, rule.ID)
		}

		matched := false
		switch rule.TargetType {
		case "DOMAIN_EXACT":
			matched = domain == target
		case "DOMAIN_SUFFIX":
			matched = domain == target || strings.HasSuffix(domain, "."+target)
		default:
			return Decision{}, fmt.Errorf("%w: target type %q on rule %d", ErrUnsupportedRule, rule.TargetType, rule.ID)
		}
		if matched {
			return Decision{Action: action, Matched: true, RuleID: rule.ID, Domain: domain}, nil
		}
	}
	return Decision{Action: defaultAction, Matched: false, Domain: domain}, nil
}
