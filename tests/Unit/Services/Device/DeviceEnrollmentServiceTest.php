<?php

namespace Tests\Unit\Services\Device;

use App\Models\Network;
use App\Models\User;
use App\Services\Device\DeviceEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DeviceEnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_token_is_one_time_and_activates_device(): void
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Enrollment Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $service = app(DeviceEnrollmentService::class);

        $result = $service->create(
            $network,
            'Test Phone',
            'PHONE',
            'device-enrollment-test',
            $user->id,
        );

        $credential = $service->consume(
            $result['token'],
            'fingerprint-test-001',
            'public-key-test',
        );

        $this->assertSame('ACTIVE', $credential->device->status);
        $this->assertSame('USED', $result['enrollment']->fresh()->status);

        $this->expectException(RuntimeException::class);
        $service->consume($result['token'], 'fingerprint-test-002');
    }

    public function test_expired_enrollment_token_cannot_be_consumed(): void
    {
        $user = User::factory()->create();

        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Expired Enrollment',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $service = app(DeviceEnrollmentService::class);

        $result = $service->create(
            $network,
            'Expired Device',
            'PHONE',
            'expired-enrollment-test',
            $user->id,
            5,
        );

        $result['enrollment']->update(['expires_at' => now()->subMinute()]);

        $this->expectException(RuntimeException::class);
        $service->consume($result['token'], 'fingerprint-expired');
    }
}
