<?php

namespace Tests\Unit\Services\Policy;

use App\Models\Network;
use App\Models\PolicyProfile;
use App\Services\Policy\PolicyVersionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyVersionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_creates_hashed_immutable_snapshot(): void
    {
        $user = User::factory()->create();
        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Version Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'UTC',
        ]);

        $profile = PolicyProfile::create([
            'network_id' => $network->id,
            'name' => 'My Policy',
            'key' => 'my-policy',
            'source' => 'CUSTOM',
            'status' => 'DRAFT',
        ]);

        $profile->rules()->create([
            'target_type' => 'DOMAIN',
            'target' => 'example.com',
            'action' => 'BLOCK',
            'priority' => 100,
        ]);

        $version = app(PolicyVersionService::class)->publish($profile, $user->id);

        $this->assertSame(1, $version->version);
        $this->assertSame('ACTIVE', $version->status);
        $this->assertSame('ACTIVE', $profile->fresh()->status);
        $this->assertSame(64, strlen($version->snapshot_hash));
        $this->assertNotEmpty($version->snapshot['rules']);
    }

    public function test_rollback_creates_a_new_version(): void
    {
        $user = User::factory()->create();
        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Rollback Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'UTC',
        ]);

        $profile = PolicyProfile::create([
            'network_id' => $network->id,
            'name' => 'Rollback Policy',
            'key' => 'rollback-policy',
            'source' => 'CUSTOM',
            'status' => 'DRAFT',
        ]);

        $profile->rules()->create([
            'target_type' => 'DOMAIN',
            'target' => 'example.com',
            'action' => 'BLOCK',
            'priority' => 100,
        ]);

        $service = app(PolicyVersionService::class);
        $first = $service->publish($profile, $user->id);

        $profile->update(['status' => 'DRAFT']);
        $profile->rules()->create([
            'target_type' => 'DOMAIN',
            'target' => 'test.example.com',
            'action' => 'ALLOW',
            'priority' => 50,
        ]);

        $second = $service->publish($profile, $user->id);
        $rolledBack = $service->rollback($profile, $first->version, $user->id);

        $this->assertSame(3, $rolledBack->version);
        $this->assertSame($first->snapshot_hash, $rolledBack->snapshot_hash);
        $this->assertSame('SUPERSEDED', $second->fresh()->status);
        $this->assertSame('ACTIVE', $rolledBack->fresh()->status);
    }
}
