<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Models\ConfigurationDeployment;
use App\Models\ConfigurationVersion;
use App\Models\NetworkNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NodeRuntimeController extends Controller
{
    public function heartbeat(Request $request, NetworkNode $node): JsonResponse
    {
        $this->assertAuthenticatedNode($request, $node);

        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:50'],
            'config_version' => ['nullable', 'integer', 'min:0'],
            'capabilities' => ['sometimes', 'array'],
        ]);

        $node->forceFill([
            'status' => 'HEALTHY',
            'last_seen_at' => now(),
            'version' => $data['version'] ?? $node->version,
            'config_version' => $data['config_version'] ?? $node->config_version,
            'capabilities' => $data['capabilities'] ?? $node->capabilities,
        ])->save();

        return response()->json([
            'success' => true,
            'data' => [
                'node_id' => $node->id,
                'status' => $node->status,
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    public function currentConfiguration(Request $request, NetworkNode $node): JsonResponse
    {
        $this->assertAuthenticatedNode($request, $node);

        $deployment = ConfigurationDeployment::query()
            ->where('network_id', $node->network_id)
            ->where('network_node_id', $node->id)
            ->whereIn('status', ['PENDING', 'DELIVERING'])
            ->with('configuration')
            ->orderBy('id')
            ->first();

        if (!$deployment || !$deployment->configuration) {
            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        }

        if ($deployment->configuration->status !== 'PUBLISHED') {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CONFIGURATION_NOT_PUBLISHED', 'message' => 'Configuration is not published.'],
            ], 409);
        }

        $deployment->forceFill([
            'status' => 'DELIVERING',
            'started_at' => $deployment->started_at ?? now(),
        ])->save();

        return response()->json([
            'success' => true,
            'data' => [
                'deployment_id' => $deployment->id,
                'configuration_id' => $deployment->configuration->id,
                'version' => $deployment->configuration->version,
                'schema_version' => (int) $deployment->configuration->schema_version,
                'snapshot' => $deployment->configuration->snapshot,
                'snapshot_hash' => $deployment->configuration->snapshot_hash,
                'signature' => $deployment->configuration->signature,
                'signature_algorithm' => $deployment->configuration->signature_algorithm,
            ],
        ]);
    }

    public function acknowledge(Request $request, NetworkNode $node, ConfigurationDeployment $deployment): JsonResponse
    {
        $this->assertAuthenticatedNode($request, $node);

        if ($deployment->network_node_id !== $node->id || $deployment->network_id !== $node->network_id) {
            abort(404);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'FAILED'])],
            'config_version' => ['required', 'integer', 'min:1'],
            'snapshot_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'error_message' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($node, $deployment, $data): JsonResponse {
            $lockedDeployment = ConfigurationDeployment::query()
                ->lockForUpdate()
                ->with('configuration')
                ->findOrFail($deployment->id);

            $configuration = $lockedDeployment->configuration;

            if (!$configuration || $configuration->network_id !== $node->network_id) {
                abort(404);
            }

            if ((int) $configuration->version !== (int) $data['config_version']
                || !hash_equals($configuration->snapshot_hash, $data['snapshot_hash'])) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'CONFIGURATION_ACK_MISMATCH', 'message' => 'Acknowledgment does not match the assigned configuration.'],
                ], 409);
            }

            if ($data['status'] === 'FAILED') {
                $lockedDeployment->forceFill([
                    'status' => 'FAILED',
                    'completed_at' => now(),
                    'error_message' => $data['error_message'] ?? 'Node rejected configuration.',
                ])->save();

                return response()->json([
                    'success' => true,
                    'data' => ['deployment_id' => $lockedDeployment->id, 'status' => 'FAILED'],
                ]);
            }

            if (!in_array($lockedDeployment->status, ['PENDING', 'DELIVERING', 'ACTIVE'], true)) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'DEPLOYMENT_STATE_INVALID', 'message' => 'Deployment cannot be activated from its current state.'],
                ], 409);
            }

            ConfigurationDeployment::query()
                ->where('network_node_id', $node->id)
                ->where('id', '!=', $lockedDeployment->id)
                ->where('status', 'ACTIVE')
                ->update(['status' => 'SUPERSEDED']);

            $lockedDeployment->forceFill([
                'status' => 'ACTIVE',
                'completed_at' => now(),
                'error_message' => null,
            ])->save();

            $node->forceFill([
                'status' => 'HEALTHY',
                'config_version' => (int) $configuration->version,
                'last_seen_at' => now(),
            ])->save();

            $configuration->forceFill(['activated_at' => now()])->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'deployment_id' => $lockedDeployment->id,
                    'configuration_id' => $configuration->id,
                    'status' => 'ACTIVE',
                    'config_version' => (int) $configuration->version,
                ],
            ]);
        });
    }

    private function assertAuthenticatedNode(Request $request, NetworkNode $node): void
    {
        $authenticated = $request->attributes->get('authenticated_network_node');

        abort_unless($authenticated instanceof NetworkNode && $authenticated->is($node), 401);
    }
}
