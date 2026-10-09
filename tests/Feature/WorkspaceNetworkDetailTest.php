<?php

namespace Tests\Feature;

use App\Models\Network;
use App\Models\PolicyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceNetworkDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_network_details_and_policy_profiles(): void
    {
        $user = User::factory()->create();
        $network = $this->createNetwork($user, 'Campus Network');
        PolicyProfile::create([
            'network_id' => $network->id,
            'name' => 'Student Protection',
            'key' => 'student-protection',
            'source' => 'CUSTOM',
            'status' => 'DRAFT',
            'description' => 'Initial draft',
            'is_default' => false,
            'is_template' => false,
        ]);

        $this->actingAs($user)
            ->get(route('workspace.networks.show', $network->id))
            ->assertOk()
            ->assertSee('Campus Network')
            ->assertSee('Student Protection')
            ->assertSee('Create policy profile');
    }

    public function test_user_cannot_view_another_owners_network(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $network = $this->createNetwork($owner, 'Private Campus');

        $this->actingAs($other)
            ->get(route('workspace.networks.show', $network->id))
            ->assertNotFound();
    }

    public function test_owner_can_create_a_draft_policy_profile(): void
    {
        $user = User::factory()->create();
        $network = $this->createNetwork($user, 'Home Network');

        $this->actingAs($user)
            ->post(route('workspace.networks.policies.store', $network->id), [
                'name' => 'Family Protection',
                'description' => 'Initial family policy.',
            ])
            ->assertRedirect(route('workspace.networks.show', $network->id))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('policy_profiles', [
            'network_id' => $network->id,
            'name' => 'Family Protection',
            'key' => 'family-protection',
            'status' => 'DRAFT',
            'source' => 'CUSTOM',
        ]);
    }

    public function test_policy_profile_keys_are_unique_within_a_network(): void
    {
        $user = User::factory()->create();
        $network = $this->createNetwork($user, 'Office Network');

        PolicyProfile::create([
            'network_id' => $network->id,
            'name' => 'Guest Access',
            'key' => 'guest-access',
            'source' => 'CUSTOM',
            'status' => 'DRAFT',
            'is_default' => false,
            'is_template' => false,
        ]);

        $this->actingAs($user)->post(route('workspace.networks.policies.store', $network->id), [
            'name' => 'Guest Access',
        ])->assertRedirect(route('workspace.networks.show', $network->id));

        $this->assertDatabaseHas('policy_profiles', [
            'network_id' => $network->id,
            'key' => 'guest-access-2',
            'name' => 'Guest Access',
        ]);
    }

    private function createNetwork(User $owner, string $name): Network
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
