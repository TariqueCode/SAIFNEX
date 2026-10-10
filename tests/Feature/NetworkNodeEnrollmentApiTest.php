<?php

namespace Tests\Feature;

use App\Models\Network;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkNodeEnrollmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_network_owner_can_register_node_and_receive_one_time_token(): void
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Node Enrollment API Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/v1/networks/{$network->id}/nodes",
            [
                'name' => 'BD-Edge-01',
                'type' => 'EDGE',
                'region' => 'BD',
                'capabilities' => ['dns' => true, 'monitoring' => true],
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'BD-Edge-01')
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('credentials.type', 'Bearer');

        $token = $response->json('credentials.token');

        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));

        $node = $network->nodes()->where('name', 'BD-Edge-01')->firstOrFail();

        $this->assertSame(hash('sha256', $token), $node->credential_hash);
        $this->assertNotSame($token, $node->credential_hash);
        $this->assertArrayNotHasKey('credential_hash', $response->json('data'));
        $this->assertNotNull($node->credential_rotated_at);
    }

    public function test_non_owner_cannot_register_node_in_another_network(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $network = Network::create([
            'owner_id' => $owner->id,
            'name' => 'Private Network',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $this->actingAs($otherUser)->postJson(
            "/api/v1/networks/{$network->id}/nodes",
            ['name' => 'Unauthorized Node']
        )->assertNotFound();
    }

    public function test_node_registration_validates_required_name(): void
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Validation Network',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $this->actingAs($user)->postJson(
            "/api/v1/networks/{$network->id}/nodes",
            ['type' => 'EDGE']
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }
}
