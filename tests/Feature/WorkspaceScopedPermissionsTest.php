<?php

namespace Tests\Feature;

use App\Models\Network;
use App\Models\NetworkMember;
use App\Models\Permission;
use App\Models\PolicyProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceScopedPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuration_reader_can_view_but_cannot_generate_configuration(): void
    {
        [$owner, $reader, $network] = $this->networkWithMember('configuration.read');

        $this->actingAs($reader)
            ->get(route('workspace.networks.configurations.index', $network->id))
            ->assertOk();

        $this->actingAs($reader)
            ->post(route('workspace.networks.configurations.compile', $network->id))
            ->assertNotFound();
    }

    public function test_deployment_reader_can_view_but_cannot_create_deployment(): void
    {
        [, $reader, $network] = $this->networkWithMember('deployment.read');

        $this->actingAs($reader)
            ->get(route('workspace.networks.deployments.index', $network->id))
            ->assertOk();

        $this->actingAs($reader)
            ->post(route('workspace.networks.deployments.store', $network->id), [
                'configuration_id' => 1,
                'node_id' => 1,
            ])
            ->assertNotFound();
    }

    public function test_policy_reader_can_view_but_cannot_add_rules(): void
    {
        [, $reader, $network] = $this->networkWithMember('policy.read');
        $profile = PolicyProfile::create([
            'network_id' => $network->id,
            'name' => 'Reader Policy',
            'key' => 'reader-policy',
            'source' => 'CUSTOM',
            'status' => 'DRAFT',
            'is_default' => false,
            'is_template' => false,
        ]);

        $this->actingAs($reader)
            ->get(route('workspace.networks.policies.show', [$network->id, $profile->id]))
            ->assertOk();

        $this->actingAs($reader)
            ->post(route('workspace.networks.policies.rules.store', [$network->id, $profile->id]), [
                'target_type' => 'DOMAIN',
                'target' => 'example.com',
                'action' => 'BLOCK',
                'priority' => 100,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('policy_rules', [
            'profile_id' => $profile->id,
            'target' => 'example.com',
        ]);
    }

    public function test_device_reader_can_view_nodes_but_cannot_enroll_one(): void
    {
        [, $reader, $network] = $this->networkWithMember('device.read');

        $this->actingAs($reader)
            ->get(route('workspace.networks.nodes.index', $network->id))
            ->assertOk();

        $this->actingAs($reader)
            ->post(route('workspace.networks.nodes.store', $network->id), [
                'name' => 'Unauthorized Node',
                'type' => 'DNS',
                'region' => 'Asia',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('network_nodes', [
            'network_id' => $network->id,
            'name' => 'Unauthorized Node',
        ]);
    }

    /** @return array{0: User, 1: User, 2: Network} */
    private function networkWithMember(string $permissionKey): array
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $network = Network::create([
            'owner_id' => $owner->id,
            'name' => 'Permission Test Network',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $role = Role::create([
            'key' => 'test_' . str_replace('.', '_', $permissionKey),
            'name' => 'Test reader',
            'is_system' => false,
        ]);
        $permission = Permission::create([
            'key' => $permissionKey,
            'name' => 'Test permission',
        ]);
        $role->permissions()->attach($permission);

        NetworkMember::create([
            'network_id' => $network->id,
            'user_id' => $reader->id,
            'role_id' => $role->id,
            'status' => 'ACTIVE',
            'joined_at' => now(),
        ]);

        return [$owner, $reader, $network];
    }
}
