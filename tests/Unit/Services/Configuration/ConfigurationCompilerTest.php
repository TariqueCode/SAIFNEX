<?php

namespace Tests\Unit\Services\Configuration;

use App\Models\Device;
use App\Models\Network;
use App\Models\PolicyProfile;
use App\Models\User;
use App\Services\Configuration\ConfigurationCompiler;
use App\Services\Policy\PolicyVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationCompilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_compiler_builds_a_hashed_snapshot_for_an_active_device_policy(): void
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Compiler Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $device = Device::create([
            'network_id' => $network->id,
            'name' => 'Compiler Phone',
            'type' => 'PHONE',
            'identifier' => 'compiler-phone',
            'status' => 'ACTIVE',
        ]);

        $profile = PolicyProfile::create([
            'network_id' => $network->id,
            'name' => 'Compiler Policy',
            'key' => 'compiler-policy',
            'source' => 'CUSTOM',
            'status' => 'DRAFT',
            'is_default' => true,
            'is_template' => false,
        ]);

        $profile->rules()->create([
            'target_type' => 'DOMAIN',
            'target' => 'example.com',
            'action' => 'BLOCK',
            'priority' => 100,
        ]);

        app(PolicyVersionService::class)->publish($profile, $user->id);

        $compiled = app(ConfigurationCompiler::class)->compile($network);

        $this->assertSame(1, $compiled->version);
        $this->assertSame('GENERATED', $compiled->status);
        $this->assertSame(64, strlen($compiled->snapshot_hash));
        $this->assertSame(
            'BLOCK',
            $compiled->snapshot['devices'][(string) $device->id]['rules'][0]['action']
        );
    }
}
