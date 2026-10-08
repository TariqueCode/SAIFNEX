<?php

namespace App\Services\Policy;

use App\Models\PolicyProfile;
use App\Models\PolicyRule;
use App\Models\PolicyRule as Rule;
use Carbon\CarbonInterface;

class PolicyEvaluator
{
    public function __construct(
        private readonly ScheduleResolver $schedules,
    ) {}

    /**
     * Resolve the highest-priority applicable rule.
     *
     * Lower priority numbers win. Rules with the same priority
     * are resolved deterministically by their ID.
     */
    public function evaluate(
        PolicyProfile $profile,
        string $targetType,
        string $target,
        ?CarbonInterface $at = null,
    ): ?Rule {
        $at ??= now();

        $rules = $profile->rules()
            ->where('enabled', true)
            ->where(function ($query) use ($at) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $at);
            })
            ->where(function ($query) use ($at) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', $at);
            })
            ->whereIn('target_type', [$targetType, 'DOMAIN_SUFFIX'])
            ->with('schedule')
            ->get()
            ->filter(function (Rule $rule) use ($targetType, $target, $at): bool {
                if ($rule->schedule && !$this->schedules->isActive($rule->schedule, $at)) {
                    return false;
                }
                if ($rule->target_type === $targetType && $rule->target === $target) {
                    return true;
                }

                if ($rule->target_type !== 'DOMAIN_SUFFIX') {
                    return false;
                }

                return $target === $rule->target
                    || str_ends_with($target, '.'.$rule->target);
            });

        return $rules
            ->sortBy(fn (Rule $rule) => [$rule->priority, $rule->id])
            ->first();
    }
}
