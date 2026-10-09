<?php

namespace Tests\Feature;

use App\Models\Network;
use App\Models\PolicyProfile;
use App\Models\PolicyRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspacePolicyRuleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_policy_editor_and_existing_rules(): void
    {
        $user = User::factory()->create();
        [$network, $profile] = $this->createDraftProfile($user);
        $profile->rules()->create([
            'target_type' => 'DOMAIN',
            'target' => 'example.com',
            'action' => 'BLOCK',
            'priority' => 100,
            'enabled' => true,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('workspace.networks.policies.show', [$network->id, $profile->id]))
            ->assertOk()
            ->assertSee('Policy editor')
            ->assertSee('example.com')
            ->assertSee('BLOCK')
            ->assertSee('Add a rule');
    }

    public function test_owner_can_create_a_rule_on_a_draft_profile(): void
    {
        $user = User::factory()->create();
        [$network, $profile] = $this->createDraftProfile($user);

        $this->actingAs($user)
            ->post(route('workspace.networks.policies.rules.store', [$network->id, $profile->id]), [
                'target_type' => 'DOMAIN_SUFFIX',
                'target' => 'example.org',
                'action' => 'BLOCK',
                'priority' => 50,
                'enabled' => '1',
            ])
            ->assertRedirect(route('workspace.networks.policies.show', [$network->id, $profile->id]))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('policy_rules', [
            'profile_id' => $profile->id,
            'target_type' => 'DOMAIN_SUFFIX',
            'target' => 'example.org',
            'action' => 'BLOCK',
            'priority' => 50,
            'enabled' => true,
            'created_by' => $user->id,
        ]);
    }

    public function test_owner_can_update_and_delete_a_draft_rule(): void
    {
        $user = User::factory()->create();
        [$network, $profile] = $this->createDraftProfile($user);
        $rule = $profile->rules()->create([
            'target_type' => 'DOMAIN',
            'target' => 'old.example',
            'action' => 'BLOCK',
            'priority' => 100,
            'enabled' => true,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->patch(route('workspace.networks.policies.rules.update', [$network->id, $profile->id, $rule->id]), [
                'target_type' => 'DOMAIN',
                'target' => 'new.example',
                'action' => 'ALLOW',
                'priority' => 25,
                'enabled' => '1',
            ])
            ->assertRedirect(route('workspace.networks.policies.show', [$network->id, $profile->id]));

        $this->assertDatabaseHas('policy_rules', [
            'id' => $rule->id,
            'target' => 'new.example',
            'action' => 'ALLOW',
            'priority' => 25,
        ]);

        $this->delete(route('workspace.networks.policies.rules.destroy', [$network->id, $profile->id, $rule->id]))
            ->assertRedirect(route('workspace.networks.policies.show', [$network->id, $profile->id]));

        $this->assertDatabaseMissing('policy_rules', ['id' => $rule->id]);
    }

    public function test_user_cannot_edit_another_owners_policy(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        [$network, $profile] = $this->createDraftProfile($owner);

        $this->actingAs($other)
            ->get(route('workspace.networks.policies.show', [$network->id, $profile->id]))
            ->assertNotFound();

        $this->assertDatabaseMissing('policy_rules', [
            'profile_id' => $profile->id,
            'target' => 'private.example',
        ]);
    }

    public function test_active_profiles_cannot_be_edited_through_rule_ui(): void
    {
        $user = User::factory()->create();
        [$network, $profile] = $this->createDraftProfile($user);
        $profile->update(['status' => 'ACTIVE']);

        $this->actingAs($user)
            ->from(route('workspace.networks.policies.show', [$network->id, $profile->id]))
            ->post(route('workspace.networks.policies.rules.store', [$network->id, $profile->id]), [
                'target_type' => 'DOMAIN',
                'target' => 'blocked.example',
                'action' => 'BLOCK',
                'priority' => 100,
            ])
            ->assertStatus(409);

        $this->assertDatabaseMissing('policy_rules', [
            'profile_id' => $profile->id,
            'target' => 'blocked.example',
        ]);
    }

    private function createDraftProfile(User $owner): array
    {
        $network = Network::create([
            'owner_id' => $owner->id,
            'name' => 'Policy Test Network',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $profile = PolicyProfile::create([
            'network_id' => $network->id,
            'name' => 'Test Protection',
            'key' => 'test-protection',
            'source' => 'CUSTOM',
            'status' => 'DRAFT',
            'description' => 'Test policy',
            'is_default' => false,
            'is_template' => false,
        ]);

        return [$network, $profile];
    }
}
