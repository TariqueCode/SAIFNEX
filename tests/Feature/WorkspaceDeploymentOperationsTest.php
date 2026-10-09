<?php

namespace Tests\Feature;

use App\Models\Network;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceDeploymentOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_deployment_operations_page(): void
    {
        $owner = User::factory()->create();
        $network = Network::create([
            'owner_id' => $owner->id,
            'name' => 'Deployment Test Network',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $this->actingAs($owner)
            ->get(route('workspace.networks.deployments.index', $network->id))
            ->assertOk()
            ->assertSee('Deployment operations')
            ->assertSee('Deployment history');
    }

    public function test_another_user_cannot_open_deployment_operations_page(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $network = Network::create([
            'owner_id' => $owner->id,
            'name' => 'Private Network',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $this->actingAs($other)
            ->get(route('workspace.networks.deployments.index', $network->id))
            ->assertNotFound();
    }
}
