<?php

namespace Tests\Unit\Services\Policy;

use App\Models\PolicyProfile;
use App\Models\PolicyRule;
use App\Services\Policy\PolicyEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_rule_wins_over_a_lower_priority_rule(): void
    {
        $profile = PolicyProfile::create([
            'network_id' => $this->networkId(),
            'name' => 'Test',
            'key' => 'test',
            'source' => 'CUSTOM',
            'status' => 'ACTIVE',
        ]);

        $profile->rules()->create([
            'target_type' => 'DOMAIN',
            'target' => 'example.com',
            'action' => 'BLOCK',
            'priority' => 200,
        ]);

        $winner = $profile->rules()->create([
            'target_type' => 'DOMAIN',
            'target' => 'example.com',
            'action' => 'ALLOW',
            'priority' => 100,
        ]);

        $result = app(PolicyEvaluator::class)->evaluate($profile, 'DOMAIN', 'example.com');

        $this->assertTrue($result->is($winner));
        $this->assertSame('ALLOW', $result->action);
    }

    public function test_domain_suffix_rule_matches_a_subdomain(): void
    {
        $profile = PolicyProfile::create([
            'network_id' => $this->networkId(),
            'name' => 'Suffix Test',
            'key' => 'suffix-test',
            'source' => 'CUSTOM',
            'status' => 'ACTIVE',
        ]);

        $rule = $profile->rules()->create([
            'target_type' => 'DOMAIN_SUFFIX',
            'target' => 'example.com',
            'action' => 'BLOCK',
            'priority' => 100,
        ]);

        $result = app(PolicyEvaluator::class)->evaluate(
            $profile,
            'DOMAIN',
            'video.example.com',
        );

        $this->assertTrue($result->is($rule));
    }

    private function networkId(): int
    {
        return \App\Models\Network::create([
            'owner_id' => \App\Models\User::factory()->create()->id,
            'name' => 'Policy Test Network',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'UTC',
        ])->id;
    }
}
