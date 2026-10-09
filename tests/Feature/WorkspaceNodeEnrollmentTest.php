<?php

namespace Tests\Feature;

use App\Models\Network;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceNodeEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_node_management_page(): void
    {
        $user = User::factory()->create();
        $network = $this->createNetwork($user);

        $this->actingAs($user)
            ->get(route('workspace.networks.nodes.index', $network->id))
            ->assertOk()
            ->assertSee('Register an edge node')
            ->assertSee('Credential is shown only once');
    }

    public function test_owner_can_register_node_and_receive_one_time_token(): void
    {
        $user = User::factory()->create();
        $network = $this->createNetwork($user);

        $response = $this->actingAs($user)->post(route('workspace.networks.nodes.store', $network->id), [
            'name' => 'Edge Chattogram 01',
            'type' => 'EDGE',
            'region' => 'Bangladesh',
            'version' => '0.1.0',
        ]);

        $response->assertRedirect(route('workspace.networks.nodes.index', $network->id))
            ->assertSessionHas('new_node_token')
            ->assertSessionHas('new_node_name', 'Edge Chattogram 01');

        $token = session('new_node_token');
        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));

        $this->assertDatabaseHas('network_nodes', [
            'network_id' => $network->id,
            'name' => 'Edge Chattogram 01',
            'type' => 'EDGE',
            'status' => 'PENDING',
            'credential_hash' => hash('sha256', $token),
        ]);

        $this->get(route('workspace.networks.nodes.index', $network->id))
            ->assertOk()
            ->assertDontSee($token);
    }

    public function test_user_cannot_register_node_in_another_owners_network(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $network = $this->createNetwork($owner);

        $this->actingAs($other)
            ->post(route('workspace.networks.nodes.store', $network->id), [
                'name' => 'Unauthorized node',
                'type' => 'EDGE',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('network_nodes', [
            'network_id' => $network->id,
            'name' => 'Unauthorized node',
        ]);
    }

    public function test_guest_cannot_register_a_node(): void
    {
        $user = User::factory()->create();
        $network = $this->createNetwork($user);

        $this->post(route('workspace.networks.nodes.store', $network->id), [
            'name' => 'Guest node',
            'type' => 'EDGE',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('network_nodes', ['name' => 'Guest node']);
    }

    private function createNetwork(User $owner): Network
    {
        return Network::create([
            'owner_id' => $owner->id,
            'name' => 'Node Test Network',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);
    }
}
