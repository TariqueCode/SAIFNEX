<?php

namespace Tests\Unit\Services\Configuration;

use App\Models\ConfigurationDeployment;
use App\Models\ConfigurationVersion;
use App\Models\Network;
use App\Models\NetworkNode;
use App\Models\User;
use App\Services\Configuration\ConfigurationDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ConfigurationDeploymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_configuration_creates_a_pending_deployment(): void
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Deployment Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $configuration = ConfigurationVersion::create([
            'network_id' => $network->id,
            'version' => 1,
            'status' => 'PUBLISHED',
            'schema_version' => '1',
            'snapshot' => ['schema_version' => 1, 'network' => ['id' => $network->id]],
            'snapshot_hash' => str_repeat('a', 64),
            'generated_at' => now(),
            'published_at' => now(),
        ]);

        $node = NetworkNode::create([
            'network_id' => $network->id,
            'name' => 'BD-01',
            'type' => 'EDGE',
            'region' => 'BD',
            'status' => 'HEALTHY',
            'capabilities' => ['dns' => true],
        ]);

        $deployment = app(ConfigurationDeploymentService::class)->create($configuration, $node);

        $this->assertInstanceOf(ConfigurationDeployment::class, $deployment);
        $this->assertSame('PENDING', $deployment->status);
        $this->assertSame($configuration->id, $deployment->configuration_version_id);
        $this->assertSame($node->id, $deployment->network_node_id);
    }

    public function test_unpublished_configuration_cannot_be_deployed(): void
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Blocked Deployment Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $configuration = ConfigurationVersion::create([
            'network_id' => $network->id,
            'version' => 1,
            'status' => 'STAGED',
            'schema_version' => '1',
            'snapshot' => ['schema_version' => 1, 'network' => ['id' => $network->id]],
            'snapshot_hash' => str_repeat('b', 64),
            'generated_at' => now(),
        ]);

        $node = NetworkNode::create([
            'network_id' => $network->id,
            'name' => 'BD-02',
            'type' => 'EDGE',
            'status' => 'HEALTHY',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be PUBLISHED before deployment.');

        app(ConfigurationDeploymentService::class)->create($configuration, $node);
    }

    public function test_deployment_is_idempotent_for_same_configuration_and_node(): void
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Idempotent Deployment Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $configuration = ConfigurationVersion::create([
            'network_id' => $network->id,
            'version' => 1,
            'status' => 'PUBLISHED',
            'schema_version' => '1',
            'snapshot' => ['schema_version' => 1, 'network' => ['id' => $network->id]],
            'snapshot_hash' => str_repeat('c', 64),
            'generated_at' => now(),
            'published_at' => now(),
        ]);

        $node = NetworkNode::create([
            'network_id' => $network->id,
            'name' => 'BD-03',
            'type' => 'EDGE',
            'status' => 'HEALTHY',
        ]);

        $service = app(ConfigurationDeploymentService::class);
        $first = $service->create($configuration, $node);
        $second = $service->create($configuration, $node);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ConfigurationDeployment::query()->count());
    }
}
