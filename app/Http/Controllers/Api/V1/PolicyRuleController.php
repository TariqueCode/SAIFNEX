<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PolicyProfile;
use App\Models\PolicyRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PolicyRuleController extends Controller
{
    public function store(Request $request, PolicyProfile $policy): JsonResponse
    {
        $this->authorizeManage($policy);

        $data = $this->validated($request);
        $rule = $policy->rules()->create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $rule,
        ], 201);
    }

    public function update(Request $request, PolicyProfile $policy, PolicyRule $rule): JsonResponse
    {
        $this->ensureRuleBelongsToPolicy($policy, $rule);
        $this->authorizeManage($policy);

        $rule->update($this->validated($request, true));

        return response()->json([
            'success' => true,
            'data' => $rule->fresh(),
        ]);
    }

    public function destroy(PolicyProfile $policy, PolicyRule $rule): JsonResponse
    {
        $this->ensureRuleBelongsToPolicy($policy, $rule);
        $this->authorizeManage($policy);

        $rule->delete();

        return response()->json([
            'success' => true,
            'data' => null,
        ]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'target_type' => [$required, 'string', 'max:40'],
            'target' => [$required, 'string', 'max:255'],
            'action' => [$required, 'string', 'max:30'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'enabled' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }

    private function authorizeManage(PolicyProfile $policy): void
    {
        $user = request()->user();
        $network = $policy->network;

        if ($network->owner_id === $user->id) {
            return;
        }

        $member = $network->members()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'ACTIVE')
            ->first();

        if (!$member || !$member->role->permissions()->where('key', 'policy.manage')->exists()) {
            abort(403, 'You do not have permission to manage policies in this network.');
        }
    }

    private function ensureRuleBelongsToPolicy(PolicyProfile $policy, PolicyRule $rule): void
    {
        abort_unless($rule->profile_id === $policy->id, 404);
    }
}
