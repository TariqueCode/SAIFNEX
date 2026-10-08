<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Network;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_own_a_network_and_attach_a_device(): void
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Home Network',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $device = Device::create([
            'network_id' => $network->id,
            'name' => 'Saif Phone',
            'type' => 'ANDROID',
            'identifier' => 'device-test-001',
            'status' => 'PENDING',
            'capabilities' => ['dns' => true],
        ]);

        $this->assertTrue($network->owner->is($user));
        $this->assertTrue($network->devices->first()->is($device));
    }

    public function test_network_roles_and_permissions_are_scoped_through_the_pivot(): void
    {
        $role = Role::create([
            'key' => 'test_viewer',
            'name' => 'Test Viewer',
            'is_system' => false,
        ]);

        $permission = $role->permissions()->create([
            'key' => 'test.read',
            'name' => 'Test read',
        ]);

        $this->assertTrue($role->permissions->contains($permission));
        $this->assertTrue($permission->roles->contains($role));
    }
}
