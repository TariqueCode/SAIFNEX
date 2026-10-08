<?php

namespace App\Services\Configuration;

use App\Models\ConfigurationDeployment;
use App\Models\ConfigurationVersion;
use App\Models\NetworkNode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConfigurationDeploymentService
{
    public function create(ConfigurationVersion $configuration, NetworkNode $node): ConfigurationDeployment
    {
        return DB::transaction(function () use ($configuration, $node) {
            $configuration = ConfigurationVersion::query()->lockForUpdate()->findOrFail($configuration->id);
            $node = NetworkNode::query()->lockForUpdate()->findOrFail($node->id);

            if ($configuration->network_id !== $node->network_id) {
                throw new RuntimeException('Configuration and node belong to different networks.');
            }

            if ($configuration->status !== 'PUBLISHED') {
                throw new RuntimeException(
                    "Configuration {$configuration->version} must be PUBLISHED before deployment."
                );
            }

            if (in_array($node->status, ['REVOKED', 'OFFLINE', 'MAINTENANCE'], true)) {
                throw new RuntimeException(
                    "Node {$node->name} cannot receive deployments while in status {$node->status}."
                );
            }

            $existing = ConfigurationDeployment::query()
                ->where('configuration_version_id', $configuration->id)
                ->where('network_node_id', $node->id)
                ->first();

            if ($existing) {
                return $existing->fresh(['configuration', 'node']);
            }

            return ConfigurationDeployment::create([
                'network_id' => $configuration->network_id,
                'configuration_version_id' => $configuration->id,
                'network_node_id' => $node->id,
                'status' => 'PENDING',
                'requested_at' => now(),
            ])->fresh(['configuration', 'node']);
        });
    }
}
