<?php

namespace Tests\Unit\Services\Policy;

use App\Services\Policy\PolicyRuleTimeNormalizer;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PolicyRuleTimeNormalizerTest extends TestCase
{
    public function test_naive_rule_times_are_interpreted_in_the_network_timezone_and_stored_as_utc(): void
    {
        $normalized = app(PolicyRuleTimeNormalizer::class)->normalize([
            'starts_at' => '2026-10-08 09:00:00',
            'expires_at' => '2026-10-08 13:00:00',
        ], 'Asia/Dhaka');

        $this->assertTrue($normalized['starts_at']->equalTo(
            CarbonImmutable::parse('2026-10-08T03:00:00Z')
        ));
        $this->assertTrue($normalized['expires_at']->equalTo(
            CarbonImmutable::parse('2026-10-08T07:00:00Z')
        ));
        $this->assertSame('UTC', $normalized['expires_at']->timezoneName);
    }

    public function test_explicit_timezone_in_rule_times_is_preserved_as_the_same_instant(): void
    {
        $normalized = app(PolicyRuleTimeNormalizer::class)->normalize([
            'expires_at' => '2026-10-08T13:00:00+06:00',
        ], 'Asia/Dhaka');

        $this->assertTrue($normalized['expires_at']->equalTo(
            CarbonImmutable::parse('2026-10-08T07:00:00Z')
        ));
        $this->assertSame('UTC', $normalized['expires_at']->timezoneName);
    }
}
