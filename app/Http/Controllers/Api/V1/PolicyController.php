<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Models\PolicyProfile;
use App\Services\Policy\PolicyVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PolicyController extends Controller
{
    public function index(Network $network): JsonResponse
    {
        $this->authorizeNetwork($network);

        return response()->json([
            'success' => true,
            'data' => $network->policyProfiles()->with('rules')->latest()->get(),
        ]);
    }

    public function store(Request $request, Network $network): JsonResponse
    {
        $this->authorizeNetwork($network, 'policy.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'source' => ['sometimes', 'string', 'max:30'],
            'description' => ['nullable', 'string'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $profile = $network->policyProfiles()->create([
            ...$data,
            'source' => $data['source'] ?? 'CUSTOM',
            'status' => 'DRAFT',
        ]);

        return response()->json([
            'success' => true,
            'data' => $profile,
        ], 201);
    }

    public function show(PolicyProfile $policy): JsonResponse
    {
        $this->authorizeNetwork($policy->network);

        return response()->json([
            'success' => true,
            'data' => $policy->load('rules'),
        ]);
    }

    public function publish(Request $request, PolicyProfile $policy, PolicyVersionService $versions): JsonResponse
    {
        $this->authorizeNetwork($policy->network, 'policy.manage');

        try {
            $version = $versions->publish($policy, $request->user()->id);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'POLICY_VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $version,
        ], 201);
    }

    public function rollback(Request $request, PolicyProfile $policy, PolicyVersionService $versions): JsonResponse
    {
        $this->authorizeNetwork($policy->network, 'policy.manage');

        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $version = $versions->rollback($policy, $data['version'], $request->user()->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'POLICY_VERSION_NOT_FOUND',
                    'message' => 'The requested policy version does not exist.',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $version,
        ], 201);
    }

    public function update(Request $request, PolicyProfile $policy): JsonResponse
    {
        $this->authorizeNetwork($policy->network, 'policy.manage');

        if ($policy->status === 'ACTIVE') {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'POLICY_ACTIVE_IMMUTABLE',
                    'message' => 'An active policy cannot be edited directly. Create a new draft version first.',
                ],
            ], 409);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $policy->update($data);

        return response()->json([
            'success' => true,
            'data' => $policy->fresh(),
        ]);
    }

    private function authorizeNetwork(Network $network, string $permission = 'network.read'): void
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
