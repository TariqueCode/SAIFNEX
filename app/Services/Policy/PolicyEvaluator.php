<?php

namespace App\Services\Policy;

use App\Models\PolicyProfile;
use App\Models\PolicyRule;
use Carbon\CarbonInterface;

class PolicyEvaluator
{
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
    ): ?PolicyRule {
        $at ??= now();

        return $profile->rules()
            ->where('enabled', true)
            ->where(function ($query) use ($at) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $at);
            })
            ->where(function ($query) use ($at) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', $at);
            })
            ->where(function ($query) use ($targetType, $target) {
                $query->where(function ($q) use ($targetType, $target) {
                    $q->where('target_type', $targetType)
                        ->where('target', $target);
                })->orWhere(function ($q) use ($target) {
                    $q->where('target_type', 'DOMAIN_SUFFIX')
                        ->where(function ($suffix) use ($target) {
                            $suffix->where('target', $target)
                                ->orWhereRaw('? LIKE CONCAT("%.", target)', [$target]);
                        });
                });
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
    }
}
