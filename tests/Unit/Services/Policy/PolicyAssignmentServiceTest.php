<?php

namespace Tests\Unit\Services\Policy;

use App\Models\Device;
use App\Models\Network;
use App\Models\PolicyProfile;
use App\Models\User;
use App\Services\Policy\PolicyAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PolicyAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_policy_cannot_be_assigned_to_a_device(): void
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Assignment Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $device = Device::create([
            'network_id' => $network->id,
            'name' => 'Test Device',
            'type' => 'PHONE',
            'identifier' => 'assignment-test-device',
            'status' => 'ACTIVE',
        ]);

        $profile = PolicyProfile::create([
            'network_id' => $network->id,
            'name' => 'Draft Policy',
            'key' => 'draft-policy',
            'source' => 'CUSTOM',
            'status' => 'DRAFT',
            'is_default' => false,
            'is_template' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only an active policy can be assigned to a device.');

        app(PolicyAssignmentService::class)->assignToDevice($device, $profile, $user->id);
    }

    public function test_active_policy_can_be_assigned_to_a_device(): void
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Active Assignment Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $device = Device::create([
            'network_id' => $network->id,
            'name' => 'Active Device',
            'type' => 'PHONE',
            'identifier' => 'active-assignment-device',
            'status' => 'ACTIVE',
        ]);

        $profile = PolicyProfile::create([
            'network_id' => $network->id,
            'name' => 'Active Policy',
            'key' => 'active-policy',
            'source' => 'CUSTOM',
            'status' => 'ACTIVE',
            'is_default' => false,
            'is_template' => false,
        ]);

        app(PolicyAssignmentService::class)->assignToDevice($device, $profile, $user->id);

        $this->assertDatabaseHas('device_policy_assignments', [
            'device_id' => $device->id,
            'profile_id' => $profile->id,
        ]);
    }
}
