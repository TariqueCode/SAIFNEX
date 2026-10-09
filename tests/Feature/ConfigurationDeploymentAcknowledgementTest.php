<?php

namespace Tests\Feature;

use App\Models\ConfigurationDeployment;
use App\Models\ConfigurationVersion;
use App\Models\Network;
use App\Models\NetworkNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationDeploymentAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    public function test_acknowledgement_with_wrong_version_or_hash_is_rejected_without_activation(): void
    {
        [$network, $node, $token] = $this->makeNode();
        $configuration = $this->makeConfiguration($network, 3, str_repeat('a', 64));
        $deployment = $this->makeDeployment($network, $node, $configuration, 'DELIVERING');

        $this->withToken($token)
            ->postJson("/api/internal/v1/nodes/{$node->id}/deployments/{$deployment->id}/ack", [
                'status' => 'ACTIVE',
                'config_version' => 2,
                'snapshot_hash' => $configuration->snapshot_hash,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFIGURATION_ACK_MISMATCH');

        $this->assertSame('DELIVERING', $deployment->fresh()->status);
        $this->assertNull($node->fresh()->config_version);
    }

    public function test_valid_acknowledgement_activates_deployment_and_supersedes_previous_active_deployment(): void
    {
        [$network, $node, $token] = $this->makeNode();

        $previousConfiguration = $this->makeConfiguration($network, 1, str_repeat('b', 64));
        $previousDeployment = $this->makeDeployment($network, $node, $previousConfiguration, 'ACTIVE');

        $configuration = $this->makeConfiguration($network, 2, str_repeat('c', 64));
        $deployment = $this->makeDeployment($network, $node, $configuration, 'DELIVERING');

        $this->withToken($token)
            ->postJson("/api/internal/v1/nodes/{$node->id}/deployments/{$deployment->id}/ack", [
                'status' => 'ACTIVE',
                'config_version' => 2,
                'snapshot_hash' => $configuration->snapshot_hash,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.config_version', 2);

        $this->assertSame('SUPERSEDED', $previousDeployment->fresh()->status);
        $this->assertSame('ACTIVE', $deployment->fresh()->status);
        $this->assertSame(2, $node->fresh()->config_version);
        $this->assertNotNull($configuration->fresh()->activated_at);
    }

    private function makeNode(): array
    {
        $user = User::factory()->create();
        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Deployment Ack Network '.$user->id,
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $token = bin2hex(random_bytes(32));
        $node = NetworkNode::create([
            'network_id' => $network->id,
            'name' => 'edge-'.$user->id,
            'type' => 'EDGE',
            'status' => 'PENDING',
        ]);
        $node->forceFill([
            'credential_hash' => hash('sha256', $token),
            'credential_rotated_at' => now(),
        ])->save();

        return [$network, $node->fresh(), $token];
    }

    private function makeConfiguration(Network $network, int $version, string $hash): ConfigurationVersion
    {
        return ConfigurationVersion::create([
            'network_id' => $network->id,
            'version' => $version,
            'status' => 'PUBLISHED',
            'schema_version' => '1',
            'snapshot' => ['schema_version' => 1, 'network' => ['id' => $network->id]],
            'snapshot_hash' => $hash,
            'signature' => 'test-signature-envelope',
            'signature_algorithm' => 'Ed25519',
            'generated_at' => now(),
            'published_at' => now(),
        ]);
    }

    private function makeDeployment(
        Network $network,
        NetworkNode $node,
        ConfigurationVersion $configuration,
        string $status
    ): ConfigurationDeployment {
        return ConfigurationDeployment::create([
            'network_id' => $network->id,
            'configuration_version_id' => $configuration->id,
            'network_node_id' => $node->id,
            'status' => $status,
            'requested_at' => now(),
            'started_at' => $status === 'DELIVERING' ? now() : null,
            'completed_at' => $status === 'ACTIVE' ? now() : null,
        ]);
    }
}
