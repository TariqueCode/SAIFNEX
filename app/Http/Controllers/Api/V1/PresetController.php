<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Services\Policy\PresetPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresetController extends Controller
{
    public function index(Network $network): JsonResponse
    {
        $this->authorizeNetwork($network);

        return response()->json([
            'success' => true,
            'data' => collect(PresetPolicyService::PRESETS)->map(fn (string $key) => [
                'key' => $key,
                'name' => str_replace('_', ' ', $key),
            ])->values(),
        ]);
    }

    public function store(Request $request, Network $network, PresetPolicyService $presets): JsonResponse
    {
        $this->authorizeNetwork($network, 'policy.manage');

        $data = $request->validate([
            'preset_key' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $profile = $presets->createFromPreset(
            $network,
            $data['preset_key'],
            $data['name'],
            $request->user()->id,
        );

        return response()->json([
            'success' => true,
            'data' => $profile,
        ], 201);
    }

    private function authorizeNetwork(Network $network, string $permission = 'policy.read'): void
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
