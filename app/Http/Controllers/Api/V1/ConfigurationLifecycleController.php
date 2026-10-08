<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConfigurationVersion;
use App\Services\Configuration\ConfigurationLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigurationLifecycleController extends Controller
{
    public function validate(Request $request, ConfigurationVersion $configuration, ConfigurationLifecycleService $lifecycle): JsonResponse
    {
        $this->authorizeNetwork($configuration->network);
        return response()->json(['success' => true, 'data' => $lifecycle->validate($configuration)]);
    }

    public function stage(Request $request, ConfigurationVersion $configuration, ConfigurationLifecycleService $lifecycle): JsonResponse
    {
        $this->authorizeNetwork($configuration->network);
        return response()->json(['success' => true, 'data' => $lifecycle->stage($configuration)]);
    }

    public function publish(Request $request, ConfigurationVersion $configuration, ConfigurationLifecycleService $lifecycle): JsonResponse
    {
        $this->authorizeNetwork($configuration->network);
        return response()->json(['success' => true, 'data' => $lifecycle->publish($configuration)]);
    }

    private function authorizeNetwork($network): void
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
            ->whereHas('permissions', fn ($query) => $query->where('key', 'policy.manage'))
            ->exists();

        if (!$hasPermission) {
            abort(403, 'You do not have permission to manage network configuration.');
        }
    }
}
