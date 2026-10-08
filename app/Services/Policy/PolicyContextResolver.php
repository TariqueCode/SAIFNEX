<?php

namespace App\Services\Policy;

use App\Models\Device;
use App\Models\PolicyProfile;
use Carbon\CarbonInterface;

class PolicyContextResolver
{
    public function __construct(
        private readonly ScheduleResolver $schedules,
    ) {}

    /**
     * Resolve the effective policy profile for a device at an instant.
     *
     * Assignment precedence:
     * 1. Active device assignment
     * 2. Network default profile
     * 3. No profile
     */
    public function resolve(Device $device, ?CarbonInterface $at = null): ?PolicyProfile
    {
        $at ??= now();

        $assignment = $device->policyAssignments()
            ->with('profile')
            ->where(function ($query) use ($at) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $at);
            })
            ->where(function ($query) use ($at) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', $at);
            })
            ->latest('id')
            ->first();

        if ($assignment?->profile) {
            return $assignment->profile;
        }

        return $device->network
            ->policyProfiles()
            ->where('is_default', true)
            ->where('status', 'ACTIVE')
            ->first();
    }
}
