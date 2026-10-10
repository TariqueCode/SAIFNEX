<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Models\NetworkNode;
use App\Services\Access\NetworkAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NetworkNodeController extends Controller
{
    public function index(Request $request, Network $network): JsonResponse
    {
        app(NetworkAccess::class)->require($request->user(), $network->id, 'device.read');

        return response()->json([
            'success' => true,
            'data' => $network->nodes()->latest('id')->get(),
        ]);
    }

    public function store(Request $request, Network $network): JsonResponse
    {
        app(NetworkAccess::class)->require($request->user(), $network->id, 'device.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['sometimes', 'string', 'max:40'],
            'region' => ['nullable', 'string', 'max:80'],
            'endpoint' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:50'],
            'capabilities' => ['nullable', 'array'],
        ]);

        $token = bin2hex(random_bytes(32));

        $node = $network->nodes()->create([
            ...$data,
            'status' => 'PENDING',
        ]);

        $node->forceFill([
            'credential_hash' => hash('sha256', $token),
            'credential_rotated_at' => now(),
        ])->save();

        return response()->json([
            'success' => true,
            'data' => $node->fresh(),
            // Shown only once. Store securely on the node; never log or commit it.
            'credentials' => [
                'token' => $token,
                'type' => 'Bearer',
            ],
        ], 201);
    }

    public function show(Request $request, NetworkNode $node): JsonResponse
    {
        app(NetworkAccess::class)->require($request->user(), $node->network_id, 'device.read');

        return response()->json([
            'success' => true,
            'data' => $node->load('deployments'),
        ]);
    }

}
