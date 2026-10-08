<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConfigurationDeployment;
use App\Models\ConfigurationVersion;
use App\Models\NetworkNode;
use App\Services\Configuration\ConfigurationDeploymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigurationDeploymentController extends Controller
{
    public function store(
        Request $request,
        ConfigurationVersion $configuration,
        NetworkNode $node,
        ConfigurationDeploymentService $deploymentService
    ): JsonResponse {
        if ($configuration->network_id !== $node->network_id) {
            abort(404);
        }

        $this->authorizeNetwork($configuration->network);

        $deployment = $deploymentService->create($configuration, $node);

        return response()->json([
            'success' => true,
            'data' => $deployment,
        ], 201);
    }

    public function show(Request $request, ConfigurationDeployment $deployment): JsonResponse
    {
        $this->authorizeNetwork($deployment->network);

        return response()->json([
            'success' => true,
            'data' => $deployment->load(['configuration', 'node']),
        ]);
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
            abort(403, 'You do not have permission to manage configuration deployments.');
        }
    }
}
