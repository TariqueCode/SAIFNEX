<?php

namespace Tests\Unit\Services\Policy;

use App\Models\Network;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Policy\ScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_is_active_inside_weekday_window(): void
    {
        $schedule = $this->schedule([
            'days' => [1, 2, 3, 4, 5],
            'start' => '09:00',
            'end' => '17:00',
        ]);

        $at = CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Dhaka');

        $this->assertTrue(app(ScheduleResolver::class)->isActive($schedule, $at));
    }

    public function test_schedule_supports_overnight_window(): void
    {
        $schedule = $this->schedule([
            'days' => [4],
            'start' => '22:00',
            'end' => '06:00',
        ]);

        $at = CarbonImmutable::parse('2026-10-08 23:30:00', 'Asia/Dhaka');

        $this->assertTrue(app(ScheduleResolver::class)->isActive($schedule, $at));
    }

    public function test_disabled_schedule_is_never_active(): void
    {
        $schedule = $this->schedule([
            'days' => [1, 2, 3, 4, 5, 6, 7],
            'start' => '00:00',
            'end' => '23:59',
        ]);
        $schedule->update(['enabled' => false]);

        $this->assertFalse(app(ScheduleResolver::class)->isActive($schedule, now()));
    }

    private function schedule(array $definition): Schedule
    {
        $user = User::factory()->create();
        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Schedule Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        return Schedule::create([
            'network_id' => $network->id,
            'name' => 'Test Schedule',
            'timezone' => 'Asia/Dhaka',
            'definition' => $definition,
            'enabled' => true,
        ]);
    }
}
