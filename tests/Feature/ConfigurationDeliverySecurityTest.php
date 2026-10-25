<?php

namespace Tests\Feature;

use App\Models\ConfigurationDeployment;
use App\Models\ConfigurationVersion;
use App\Models\Network;
use App\Models\NetworkNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationDeliverySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsigned_published_configuration_is_never_delivered_to_a_node(): void
    {
        $user = User::factory()->create();
        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Unsigned Delivery Test '.$user->id,
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

        $configuration = ConfigurationVersion::create([
            'network_id' => $network->id,
            'version' => 1,
            'status' => 'PUBLISHED',
            'schema_version' => '1',
            'snapshot' => ['schema_version' => 1, 'network' => ['id' => $network->id]],
            'snapshot_hash' => str_repeat('d', 64),
            'signature' => null,
            'signature_algorithm' => null,
            'generated_at' => now(),
            'published_at' => now(),
        ]);

        $deployment = ConfigurationDeployment::create([
            'network_id' => $network->id,
            'configuration_version_id' => $configuration->id,
            'network_node_id' => $node->id,
            'status' => 'PENDING',
            'requested_at' => now(),
        ]);

        $this->withToken($token)
            ->getJson("/api/internal/v1/nodes/{$node->id}/configuration")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'CONFIGURATION_SIGNATURE_MISSING');

        $this->assertSame('PENDING', $deployment->fresh()->status);
        $this->assertNull($deployment->fresh()->started_at);
    }
}
