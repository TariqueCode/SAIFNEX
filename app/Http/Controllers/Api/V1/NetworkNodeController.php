<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Models\NetworkNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NetworkNodeController extends Controller
{
    public function index(Request $request, Network $network): JsonResponse
    {
        $this->authorizeNetwork($network);

        return response()->json([
            'success' => true,
            'data' => $network->nodes()->latest('id')->get(),
        ]);
    }

    public function store(Request $request, Network $network): JsonResponse
    {
        $this->authorizeNetwork($network);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['sometimes', 'string', 'max:40'],
            'region' => ['nullable', 'string', 'max:80'],
            'endpoint' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:50'],
            'capabilities' => ['nullable', 'array'],
        ]);

        $node = $network->nodes()->create([
            ...$data,
            'status' => 'PENDING',
        ]);

        return response()->json([
            'success' => true,
            'data' => $node,
        ], 201);
    }

    public function show(Request $request, NetworkNode $node): JsonResponse
    {
        $this->authorizeNetwork($node->network);

        return response()->json([
            'success' => true,
            'data' => $node->load('deployments'),
        ]);
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
            ->whereHas('permissions', fn ($query) => $query->where('key', 'network.manage'))
            ->exists();

        if (!$hasPermission) {
            abort(403, 'You do not have permission to manage network nodes.');
        }
    }
}
