<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Network;
use App\Models\PolicyProfile;
use App\Services\Policy\PolicyAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PolicyAssignmentController extends Controller
{
    public function setDefault(
        Request $request,
        Network $network,
        PolicyProfile $policy,
        PolicyAssignmentService $assignments,
    ): JsonResponse {
        $this->authorizeNetwork($network, 'policy.manage');
        abort_unless($policy->network_id === $network->id, 404);

        return response()->json([
            'success' => true,
            'data' => $assignments->setNetworkDefault($policy),
        ]);
    }

    public function assignDevice(
        Request $request,
        Device $device,
        PolicyProfile $policy,
        PolicyAssignmentService $assignments,
    ): JsonResponse {
        $network = $device->network;

        $this->authorizeNetwork($network, 'policy.manage');
        abort_unless($policy->network_id === $network->id, 404);

        $assignments->assignToDevice($device, $policy, $request->user()->id);

        return response()->json([
            'success' => true,
            'data' => $device->policyAssignments()->with('profile')->latest('id')->first(),
        ], 201);
    }

    private function authorizeNetwork(Network $network, string $permission): void
    {
        $user = request()->user();

        if ($network->owner_id === $user->id) {
            return;
        }

        $member = $network->members()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'ACTIVE')
            ->first();

        if (!$member) {
            abort(403, 'You do not have permission to access this network.');
        }

        $hasPermission = \App\Models\Role::query()
            ->whereKey($member->pivot->role_id)
            ->whereHas('permissions', fn ($query) => $query->where('key', $permission))
            ->exists();

        if (!$hasPermission) {
            abort(403, 'You do not have permission to access this network.');
        }
    }
}
