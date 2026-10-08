<?php

namespace App\Services\Policy;

use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ScheduleResolver
{
    /**
     * Determine whether a schedule is active at a given instant.
     *
     * Definition format:
     * {
     *   "days": [1,2,3,4,5],
     *   "start": "08:00",
     *   "end": "17:00"
     * }
     *
     * ISO weekday numbers are used: 1 = Monday ... 7 = Sunday.
     * An overnight window such as 22:00 -> 06:00 is supported.
     */
    public function isActive(Schedule $schedule, ?CarbonInterface $at = null): bool
    {
        if (!$schedule->enabled) {
            return false;
        }

        $time = CarbonImmutable::instance($at ?? now())->setTimezone($schedule->timezone);
        $definition = $schedule->definition ?? [];

        $days = array_map('intval', $definition['days'] ?? [1, 2, 3, 4, 5, 6, 7]);
        $start = $definition['start'] ?? '00:00';
        $end = $definition['end'] ?? '23:59:59';

        if (!in_array($time->isoWeekday(), $days, true)) {
            return false;
        }

        $current = $time->format('H:i:s');
        $start = strlen($start) === 5 ? $start . ':00' : $start;
        $end = strlen($end) === 5 ? $end . ':00' : $end;

        if ($start === $end) {
            return true;
        }

        if ($start < $end) {
            return $current >= $start && $current <= $end;
        }

        // Overnight window, e.g. 22:00 -> 06:00.
        return $current >= $start || $current <= $end;
    }

    /**
     * Return enabled schedules that are active at the given instant.
     */
    public function activeForNetwork(int $networkId, ?CarbonInterface $at = null)
    {
        $at ??= now();

        return Schedule::query()
            ->where('network_id', $networkId)
            ->where('enabled', true)
            ->get()
            ->filter(fn (Schedule $schedule) => $this->isActive($schedule, $at))
            ->values();
    }
}
