<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Services\Device\DeviceEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DeviceEnrollmentController extends Controller
{
    public function create(Request $request, Network $network, DeviceEnrollmentService $enrollments): JsonResponse
    {
        $this->authorizeNetwork($network);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['sometimes', 'string', 'max:50'],
            'identifier' => ['required', 'string', 'max:191'],
            'ttl_minutes' => ['sometimes', 'integer', 'min:5', 'max:60'],
        ]);

        $result = $enrollments->create(
            $network,
            $data['name'],
            $data['type'] ?? 'UNKNOWN',
            $data['identifier'],
            $request->user()->id,
            $data['ttl_minutes'] ?? 15,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'device' => $result['device'],
                'enrollment' => $result['enrollment'],
                'token' => $result['token'],
            ],
        ], 201);
    }

    public function consume(Request $request, DeviceEnrollmentService $enrollments): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:32', 'max:128'],
            'fingerprint' => ['required', 'string', 'max:191'],
            'public_key' => ['nullable', 'string'],
        ]);

        try {
            $credential = $enrollments->consume(
                $data['token'],
                $data['fingerprint'],
                $data['public_key'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DEVICE_ENROLLMENT_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'device' => $credential->device,
                'credential' => $credential,
            ],
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
            ->whereHas('permissions', fn ($query) => $query->where('key', 'device.manage'))
            ->exists();

        if (!$hasPermission) {
            abort(403, 'You do not have permission to manage devices in this network.');
        }
    }
}
