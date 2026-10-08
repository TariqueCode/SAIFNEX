<?php

namespace App\Services\Policy;

use App\Models\Device;
use App\Models\PolicyProfile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PolicyAssignmentService
{
    public function setNetworkDefault(PolicyProfile $profile): PolicyProfile
    {
        return DB::transaction(function () use ($profile) {
            PolicyProfile::query()
                ->where('network_id', $profile->network_id)
                ->whereKeyNot($profile->id)
                ->update(['is_default' => false]);

            $profile->update(['is_default' => true]);

            return $profile->fresh();
        });
    }

    public function assignToDevice(Device $device, PolicyProfile $profile, ?int $userId = null): void
    {
        if ($device->network_id !== $profile->network_id) {
            throw new RuntimeException('Device and policy must belong to the same network.');
        }

        if ($profile->status !== 'ACTIVE') {
            throw new RuntimeException('Only an active policy can be assigned to a device.');
        }

        $device->policyAssignments()->create([
            'profile_id' => $profile->id,
            'assigned_by' => $userId,
        ]);
    }
}
