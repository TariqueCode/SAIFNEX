<?php

namespace Tests\Unit\Services\Configuration;

use App\Models\ConfigurationVersion;
use App\Models\Network;
use App\Models\User;
use App\Services\Configuration\ConfigurationLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ConfigurationLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuration_moves_from_generated_to_published(): void
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Lifecycle Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $snapshot = [
            'schema_version' => 1,
            'network' => [
                'id' => $network->id,
                'timezone' => 'Asia/Dhaka',
            ],
            'generated_at' => now()->toIso8601String(),
            'devices' => [],
        ];

        $canonical = json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $configuration = ConfigurationVersion::create([
            'network_id' => $network->id,
            'version' => 1,
            'status' => 'GENERATED',
            'schema_version' => '1',
            'snapshot' => $snapshot,
            'snapshot_hash' => hash('sha256', $canonical),
            'generated_at' => now(),
        ]);

        $service = app(ConfigurationLifecycleService::class);

        $configuration = $service->validate($configuration);
        $this->assertSame('VALID', $configuration->status);

        $configuration = $service->stage($configuration);
        $this->assertSame('STAGED', $configuration->status);

        $configuration = $service->publish($configuration);
        $this->assertSame('PUBLISHED', $configuration->status);
        $this->assertNotNull($configuration->published_at);
    }

    public function test_invalid_hash_is_rejected(): void
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Invalid Hash Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $configuration = ConfigurationVersion::create([
            'network_id' => $network->id,
            'version' => 1,
            'status' => 'GENERATED',
            'schema_version' => '1',
            'snapshot' => [
                'schema_version' => 1,
                'network' => ['id' => $network->id, 'timezone' => 'Asia/Dhaka'],
                'generated_at' => now()->toIso8601String(),
                'devices' => [],
            ],
            'snapshot_hash' => str_repeat('0', 64),
            'generated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configuration snapshot hash mismatch.');

        app(ConfigurationLifecycleService::class)->validate($configuration);
    }
}
