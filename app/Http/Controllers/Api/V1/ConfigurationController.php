<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Services\Configuration\ConfigurationCompiler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigurationController extends Controller
{
    public function compile(Request $request, Network $network, ConfigurationCompiler $compiler): JsonResponse
    {
        $this->authorizeNetwork($network);

        $configuration = $compiler->compile($network);

        return response()->json([
            'success' => true,
            'data' => $configuration,
        ], 201);
    }

    private function authorizeNetwork(Network $network): void
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
            abort(403, 'You do not have permission to compile network configuration.');
        }
    }
}
