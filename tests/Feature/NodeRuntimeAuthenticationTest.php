<?php

namespace Tests\Feature;

use App\Models\Network;
use App\Models\NetworkNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeRuntimeAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_node_bearer_token_allows_heartbeat_and_updates_node_state(): void
    {
        [$node, $token] = $this->makeNode();

        $response = $this->withToken($token)->postJson(
            "/api/internal/v1/nodes/{$node->id}/heartbeat",
            [
                'version' => '0.1.0',
                'config_version' => 0,
                'capabilities' => ['dns' => true],
            ]
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.node_id', $node->id)
            ->assertJsonPath('data.status', 'HEALTHY');

        $this->assertSame('HEALTHY', $node->fresh()->status);
        $this->assertSame('0.1.0', $node->fresh()->version);
        $this->assertSame(['dns' => true], $node->fresh()->capabilities);
        $this->assertNotNull($node->fresh()->last_seen_at);
    }

    public function test_invalid_node_bearer_token_is_rejected(): void
    {
        [$node] = $this->makeNode();

        $this->withToken('not-the-node-token')
            ->postJson("/api/internal/v1/nodes/{$node->id}/heartbeat")
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'NODE_CREDENTIAL_INVALID');
    }

    public function test_revoked_node_cannot_use_a_valid_token(): void
    {
        [$node, $token] = $this->makeNode('REVOKED');

        $this->withToken($token)
            ->postJson("/api/internal/v1/nodes/{$node->id}/heartbeat")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'NODE_NOT_ACTIVE');
    }

    public function test_only_the_matching_node_token_can_authenticate_a_node(): void
    {
        [$firstNode, $firstToken] = $this->makeNode();
        [$secondNode] = $this->makeNode();

        $this->withToken($firstToken)
            ->postJson("/api/internal/v1/nodes/{$secondNode->id}/heartbeat")
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'NODE_CREDENTIAL_INVALID');
    }

    public function test_only_the_token_hash_is_persisted(): void
    {
        [$node, $token] = $this->makeNode();

        $this->assertNotSame($token, $node->credential_hash);
        $this->assertSame(hash('sha256', $token), $node->credential_hash);
        $this->assertArrayNotHasKey('credential_hash', $node->fresh()->toArray());
    }

    /**
     * @return array{0: NetworkNode, 1: string}
     */
    private function makeNode(string $status = 'PENDING'): array
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Node Runtime Test '.$user->id,
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $token = bin2hex(random_bytes(32));

        $node = NetworkNode::create([
            'network_id' => $network->id,
            'name' => 'node-'.$user->id,
            'type' => 'EDGE',
            'status' => $status,
        ]);

        $node->forceFill([
            'credential_hash' => hash('sha256', $token),
            'credential_rotated_at' => now(),
        ])->save();

        return [$node->fresh(), $token];
    }
}
