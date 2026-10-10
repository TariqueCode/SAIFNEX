<?php

namespace App\Services\Policy;

use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

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
     *
     * Invalid definitions and timezones fail closed (inactive).
     */
    public function isActive(Schedule $schedule, ?CarbonInterface $at = null): bool
    {
        if (!$schedule->enabled) {
            return false;
        }

        $definition = $schedule->definition ?? [];
        if (!is_array($definition)) {
            return false;
        }

        $days = $definition['days'] ?? [1, 2, 3, 4, 5, 6, 7];
        if (!is_array($days) || $days === []) {
            return false;
        }

        foreach ($days as $day) {
            if (!is_int($day) && !(is_string($day) && preg_match('/^[1-7]$/D', $day) === 1)) {
                return false;
            }

            if ((int) $day < 1 || (int) $day > 7) {
                return false;
            }
        }

        $start = $definition['start'] ?? '00:00';
        $end = $definition['end'] ?? '23:59:59';

        if (!is_string($start) || !is_string($end)
            || !$this->isValidTime($start) || !$this->isValidTime($end)) {
            return false;
        }

        $start = strlen($start) === 5 ? $start . ':00' : $start;
        $end = strlen($end) === 5 ? $end . ':00' : $end;

        try {
            // Validate the timezone before converting the instant.
            new DateTimeZone($schedule->timezone);
            $time = CarbonImmutable::instance($at ?? now())->setTimezone($schedule->timezone);
        } catch (Throwable) {
            return false;
        }

        $current = $time->format('H:i:s');
        $weekday = $time->isoWeekday();
        $days = array_map('intval', $days);

        if ($start === $end) {
            return in_array($weekday, $days, true);
        }

        if ($start < $end) {
            return in_array($weekday, $days, true)
                && $current >= $start
                && $current <= $end;
        }

        // For overnight windows, the after-midnight portion belongs to the
        // day on which the window started (e.g. Thursday 22:00 -> Friday 06:00).
        if ($current >= $start) {
            return in_array($weekday, $days, true);
        }

        $previousWeekday = $weekday === 1 ? 7 : $weekday - 1;

        return $current <= $end && in_array($previousWeekday, $days, true);
    }

    private function isValidTime(string $time): bool
    {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $time) !== 1) {
            return false;
        }

        return true;
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
