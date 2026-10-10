<?php

namespace Tests\Unit\Services\Configuration;

use App\Models\Device;
use App\Models\Network;
use App\Models\PolicyProfile;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Configuration\ConfigurationCompiler;
use App\Services\Policy\PolicyVersionService;
use Carbon\CarbonImmutable;
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

    public function test_compiler_embeds_schedule_and_expiry_metadata_for_runtime_enforcement(): void
    {
        $user = User::factory()->create();
        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Timed Compiler Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);
        $device = Device::create([
            'network_id' => $network->id,
            'name' => 'Timed Compiler Phone',
            'type' => 'PHONE',
            'identifier' => 'timed-compiler-phone',
            'status' => 'ACTIVE',
        ]);
        $profile = PolicyProfile::create([
            'network_id' => $network->id,
            'name' => 'Timed Compiler Policy',
            'key' => 'timed-compiler-policy',
            'source' => 'CUSTOM',
            'status' => 'DRAFT',
            'is_default' => true,
            'is_template' => false,
        ]);
        $schedule = Schedule::create([
            'network_id' => $network->id,
            'name' => 'Thursday daytime',
            'timezone' => 'Asia/Dhaka',
            'definition' => [
                'days' => [4],
                'start' => '09:00',
                'end' => '17:00',
            ],
            'enabled' => true,
        ]);

        $profile->rules()->create([
            'schedule_id' => $schedule->id,
            'target_type' => 'DOMAIN',
            'target' => 'scheduled.example',
            'action' => 'BLOCK',
            'priority' => 10,
        ]);
        Schedule::create([
            'network_id' => $network->id,
            'name' => 'Unused schedule',
            'timezone' => 'Asia/Dhaka',
            'definition' => [
                'days' => [1, 2, 3, 4, 5, 6, 7],
                'start' => '00:00',
                'end' => '23:59:59',
            ],
            'enabled' => true,
        ]);

        $profile->rules()->create([
            'target_type' => 'DOMAIN',
            'target' => 'temporary.example',
            'action' => 'BLOCK',
            'priority' => 20,
            'expires_at' => CarbonImmutable::parse('2026-10-08 13:00:00', 'Asia/Dhaka')->utc(),
        ]);

        $published = app(PolicyVersionService::class)->publish($profile, $user->id);
        $temporaryRule = collect($published->snapshot['rules'])->firstWhere('target', 'temporary.example');
        $this->assertNotNull($temporaryRule['expires_at'] ?? null);
        $this->assertTrue(
            CarbonImmutable::parse($temporaryRule['expires_at'])->equalTo(
                CarbonImmutable::parse('2026-10-08 13:00:00', 'Asia/Dhaka')
            )
        );

        $compiler = app(ConfigurationCompiler::class);

        $compiled = $compiler->compile(
            $network,
            CarbonImmutable::parse('2026-10-08 18:00:00', 'Asia/Dhaka')
        );
        $deviceSnapshot = $compiled->snapshot['devices'][(string) $device->id];

        // Keep timing metadata in the signed snapshot so the DNS runtime can
        // enforce boundaries without waiting for a control-plane recompilation.
        $this->assertSame(
            ['scheduled.example', 'temporary.example'],
            array_column($deviceSnapshot['rules'], 'target')
        );
        $scheduledRule = collect($deviceSnapshot['rules'])->firstWhere('target', 'scheduled.example');
        $this->assertSame($schedule->id, $scheduledRule['schedule_id']);
        $this->assertSame('Asia/Dhaka', $deviceSnapshot['schedules'][(string) $schedule->id]['timezone']);
        $this->assertCount(1, $deviceSnapshot['schedules']);
        $this->assertSame(
            '2026-10-08T07:00:00+00:00',
            collect($deviceSnapshot['rules'])->firstWhere('target', 'temporary.example')['expires_at']
        );
    }


    public function test_device_policy_assignment_is_inactive_at_its_exact_expiry_instant(): void
    {
        $user = User::factory()->create();
        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Assignment Expiry Boundary',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);
        $device = Device::create([
            'network_id' => $network->id,
            'name' => 'Expiry Boundary Device',
            'type' => 'PHONE',
            'identifier' => 'expiry-boundary-device',
            'status' => 'ACTIVE',
        ]);

        $defaultProfile = PolicyProfile::create([
            'network_id' => $network->id,
            'name' => 'Default Boundary Policy',
            'key' => 'default-boundary-policy',
            'source' => 'CUSTOM',
            'status' => 'DRAFT',
            'is_default' => true,
            'is_template' => false,
        ]);
        $defaultProfile->rules()->create([
            'target_type' => 'DOMAIN',
            'target' => 'default.example',
            'action' => 'ALLOW',
            'priority' => 10,
        ]);

        $assignedProfile = PolicyProfile::create([
            'network_id' => $network->id,
            'name' => 'Assigned Boundary Policy',
            'key' => 'assigned-boundary-policy',
            'source' => 'CUSTOM',
            'status' => 'DRAFT',
            'is_default' => false,
            'is_template' => false,
        ]);
        $assignedProfile->rules()->create([
            'target_type' => 'DOMAIN',
            'target' => 'assigned.example',
            'action' => 'BLOCK',
            'priority' => 10,
        ]);

        app(PolicyVersionService::class)->publish($defaultProfile, $user->id);
        app(PolicyVersionService::class)->publish($assignedProfile, $user->id);
        $device->policyAssignments()->create([
            'profile_id' => $assignedProfile->id,
            'expires_at' => CarbonImmutable::parse('2026-10-08 13:00:00', 'Asia/Dhaka')->utc(),
        ]);

        $compiled = app(ConfigurationCompiler::class)->compile(
            $network,
            CarbonImmutable::parse('2026-10-08 13:00:00', 'Asia/Dhaka')
        );
        $deviceSnapshot = $compiled->snapshot['devices'][(string) $device->id];

        $this->assertSame($defaultProfile->id, $deviceSnapshot['profile_id']);
        $this->assertSame(['default.example'], array_column($deviceSnapshot['rules'], 'target'));
    }

}
