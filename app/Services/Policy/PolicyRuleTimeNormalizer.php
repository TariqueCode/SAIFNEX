<?php

namespace App\Services\Policy;

use Carbon\CarbonImmutable;

class PolicyRuleTimeNormalizer
{
    /**
     * Store rule timestamps as UTC instants. Naive date-time inputs are treated
     * as local wall time in the network timezone (e.g. datetime-local forms).
     */
    public function normalize(array $data, string $timezone): array
    {
        foreach (['starts_at', 'expires_at'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                continue;
            }

            $value = (string) $data[$field];
            $hasExplicitTimezone = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value) === 1;
            $parsed = $hasExplicitTimezone
                ? CarbonImmutable::parse($value)
                : CarbonImmutable::parse($value, $timezone);

            $data[$field] = $parsed->utc();
        }

        return $data;
    }
}
