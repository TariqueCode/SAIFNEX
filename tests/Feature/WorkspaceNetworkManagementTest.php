<?php

namespace Tests\Feature;

use App\Models\Network;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceNetworkManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_shows_only_networks_owned_by_the_signed_in_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Network::create([
            'owner_id' => $owner->id,
            'name' => 'My Private Network',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);
        Network::create([
            'owner_id' => $other->id,
            'name' => 'Another Users Network',
            'type' => 'OFFICE',
            'status' => 'ACTIVE',
            'timezone' => 'UTC',
        ]);

        $this->actingAs($owner)
            ->get('/workspace')
            ->assertOk()
            ->assertSee('My Private Network')
            ->assertDontSee('Another Users Network')
            ->assertSee('Your networks');
    }

    public function test_signed_in_user_can_create_a_network_owned_by_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/workspace/networks', [
                'name' => 'Chattogram Home Network',
                'type' => 'HOME',
                'timezone' => 'Asia/Dhaka',
                'description' => 'Home network workspace',
            ])
            ->assertRedirect(route('workspace'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('networks', [
            'owner_id' => $user->id,
            'name' => 'Chattogram Home Network',
            'type' => 'HOME',
            'timezone' => 'Asia/Dhaka',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_network_creation_rejects_an_unsupported_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/workspace')
            ->post('/workspace/networks', [
                'name' => 'Invalid Network',
                'type' => 'UNRESTRICTED',
                'timezone' => 'Asia/Dhaka',
            ])
            ->assertRedirect('/workspace')
            ->assertSessionHasErrors('type');

        $this->assertDatabaseMissing('networks', [
            'owner_id' => $user->id,
            'name' => 'Invalid Network',
        ]);
    }

    public function test_guest_cannot_create_a_network(): void
    {
        $this->post('/workspace/networks', [
            'name' => 'Guest Network',
            'type' => 'HOME',
            'timezone' => 'Asia/Dhaka',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('networks', ['name' => 'Guest Network']);
    }
}
