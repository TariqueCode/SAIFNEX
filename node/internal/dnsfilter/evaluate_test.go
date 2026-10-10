package dnsfilter

import (
	"errors"
	"testing"
)

func TestNormalizeDomain(t *testing.T) {
	got, err := NormalizeDomain("  WWW.Example.COM. ")
	if err != nil || got != "www.example.com" {
		t.Fatalf("NormalizeDomain() = %q, %v", got, err)
	}
	for _, input := range []string{"", ".", "example..com", "-bad.example", "bad-.example", "127.0.0.1", "bad domain"} {
		if _, err := NormalizeDomain(input); !errors.Is(err, ErrInvalidDomain) {
			t.Errorf("NormalizeDomain(%q) error = %v, want ErrInvalidDomain", input, err)
		}
	}
}

func TestEvaluateExactAndSuffixRulesInPriorityOrder(t *testing.T) {
	rules := []Rule{
		{ID: 10, TargetType: "DOMAIN_SUFFIX", Target: "example.com", Action: "BLOCK", Enabled: true},
		{ID: 11, TargetType: "DOMAIN_EXACT", Target: "safe.example.com", Action: "ALLOW", Enabled: true},
	}
	decision, err := Evaluate("api.example.com.", rules, Allow)
	if err != nil || decision.Action != Block || !decision.Matched || decision.RuleID != 10 {
		t.Fatalf("suffix decision = %+v, %v", decision, err)
	}

	// First-match order is intentional; callers must sort by priority beforehand.
	decision, err = Evaluate("safe.example.com", rules[1:], Block)
	if err != nil || decision.Action != Allow || decision.RuleID != 11 {
		t.Fatalf("exact decision = %+v, %v", decision, err)
	}
}

func TestLaravelDomainRuleMatchesExactDomain(t *testing.T) {
	decision, err := Evaluate("WWW.Example.COM.", []Rule{
		{ID: 12, TargetType: "DOMAIN", Target: "example.com", Action: "BLOCK", Enabled: true},
	}, Allow)
	if err != nil || decision.Action != Block || !decision.Matched || decision.RuleID != 12 {
		t.Fatalf("Laravel DOMAIN decision = %+v, %v", decision, err)
	}
}

func TestSuffixDoesNotMatchLookalikeDomain(t *testing.T) {
	decision, err := Evaluate("notexample.com", []Rule{
		{ID: 1, TargetType: "DOMAIN_SUFFIX", Target: "example.com", Action: "BLOCK", Enabled: true},
	}, Allow)
	if err != nil || decision.Action != Allow || decision.Matched {
		t.Fatalf("lookalike decision = %+v, %v", decision, err)
	}
}

func TestEvaluateSkipsDisabledRules(t *testing.T) {
	decision, err := Evaluate("example.com", []Rule{
		{ID: 1, TargetType: "DOMAIN_EXACT", Target: "example.com", Action: "BLOCK", Enabled: false},
	}, Allow)
	if err != nil || decision.Action != Allow || decision.Matched {
		t.Fatalf("disabled-rule decision = %+v, %v", decision, err)
	}
}

func TestEvaluateFailsClosedOnUnsupportedEnabledRule(t *testing.T) {
	_, err := Evaluate("example.com", []Rule{
		{ID: 4, TargetType: "APP", Target: "example.com", Action: "BLOCK", Enabled: true},
	}, Allow)
	if !errors.Is(err, ErrUnsupportedRule) {
		t.Fatalf("error = %v, want ErrUnsupportedRule", err)
	}
}

func TestEvaluateRejectsInvalidDefaultAction(t *testing.T) {
	_, err := Evaluate("example.com", nil, Action("WARN"))
	if !errors.Is(err, ErrUnsupportedRule) {
		t.Fatalf("error = %v, want ErrUnsupportedRule", err)
	}
}
