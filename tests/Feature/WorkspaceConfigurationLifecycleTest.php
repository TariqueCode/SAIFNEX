<?php

namespace Tests\Feature;

use App\Models\ConfigurationVersion;
use App\Models\Network;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceConfigurationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_configuration_history_and_lifecycle_actions(): void
    {
        $user = User::factory()->create();
        $network = $this->createNetwork($user);
        ConfigurationVersion::create([
            'network_id' => $network->id,
            'version' => 1,
            'status' => 'GENERATED',
            'schema_version' => '1',
            'snapshot' => ['schema_version' => 1, 'network' => ['id' => $network->id, 'timezone' => 'Asia/Dhaka'], 'devices' => []],
            'snapshot_hash' => hash('sha256', 'fixture'),
            'generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('workspace.networks.configurations.index', $network->id))
            ->assertOk()
            ->assertSee('Configuration lifecycle')
            ->assertSee('Generate snapshot')
            ->assertSee('v1')
            ->assertSee('GENERATED')
            ->assertSee('Safety gate');
    }

    public function test_user_cannot_view_another_owners_configuration_history(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $network = $this->createNetwork($owner);

        $this->actingAs($other)
            ->get(route('workspace.networks.configurations.index', $network->id))
            ->assertNotFound();
    }

    public function test_configuration_id_from_another_network_cannot_be_staged(): void
    {
        $owner = User::factory()->create();
        $network = $this->createNetwork($owner);
        $otherNetwork = $this->createNetwork($owner, 'Second network');

        $configuration = ConfigurationVersion::create([
            'network_id' => $otherNetwork->id,
            'version' => 1,
            'status' => 'VALID',
            'schema_version' => '1',
            'snapshot' => ['schema_version' => 1, 'network' => ['id' => $otherNetwork->id, 'timezone' => 'Asia/Dhaka'], 'devices' => []],
            'snapshot_hash' => hash('sha256', 'other-network'),
            'generated_at' => now(),
        ]);

        $this->actingAs($owner)
            ->post(route('workspace.networks.configurations.stage', [$network->id, $configuration->id]))
            ->assertNotFound();
    }

    private function createNetwork(User $owner, string $name = 'Configuration Test Network'): Network
    {
        return Network::create([
            'owner_id' => $owner->id,
            'name' => $name,
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);
    }
}
