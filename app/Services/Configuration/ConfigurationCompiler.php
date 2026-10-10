<?php

namespace App\Services\Configuration;

use App\Models\ConfigurationVersion;
use App\Models\Network;
use App\Services\Policy\ScheduleResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ConfigurationCompiler
{
    public function __construct(
        private readonly ScheduleResolver $scheduleResolver,
        private readonly CanonicalSnapshot $canonicalSnapshot,
    ) {
    }

    public function compile(Network $network, ?CarbonInterface $at = null): ConfigurationVersion
    {
        $at ??= now();

        return DB::transaction(function () use ($network, $at) {
            $network->load([
                'devices.policyAssignments.profile.rules.schedule',
                'policyProfiles.versions',
                'schedules',
            ]);

            $profiles = $network->policyProfiles
                ->filter(fn ($profile) => $profile->status === 'ACTIVE');

            $defaultProfile = $profiles->firstWhere('is_default', true);
            $devices = [];

            foreach ($network->devices->sortBy('id') as $device) {
                if (!in_array($device->status, ['ACTIVE', 'PENDING'], true)) {
                    continue;
                }

                $assignment = $device->policyAssignments
                    ->filter(function ($assignment) use ($at) {
                        if (!$assignment->profile || $assignment->profile->status !== 'ACTIVE') {
                            return false;
                        }

                        if ($assignment->starts_at && $assignment->starts_at->gt($at)) {
                            return false;
                        }

                        if ($assignment->expires_at && $assignment->expires_at->lt($at)) {
                            return false;
                        }

                        return true;
                    })
                    ->sortByDesc('id')
                    ->first();

                $profile = $assignment?->profile ?? $defaultProfile;

                if (!$profile) {
                    continue;
                }

                $version = $profile->versions
                    ->where('status', 'ACTIVE')
                    ->sortByDesc('version')
                    ->first();

                if (!$version) {
                    continue;
                }

                $devices[(string) $device->id] = [
                    'name' => $device->name,
                    'type' => $device->type,
                    'profile_id' => $profile->id,
                    'policy_version' => $version->version,
                    'rules' => $this->activeRules(
                        $version->snapshot['rules'] ?? [],
                        $network->schedules,
                        $at
                    ),
                ];
            }

            ksort($devices, SORT_NATURAL);

            $snapshot = [
                'schema_version' => 1,
                'network' => [
                    'id' => $network->id,
                    'timezone' => $network->timezone,
                ],
                'generated_at' => $at->toIso8601String(),
                'devices' => $devices,
            ];

            $hash = hash('sha256', $this->canonicalSnapshot->encode($snapshot));

            $version = ((int) ConfigurationVersion::where('network_id', $network->id)->lockForUpdate()->max('version')) + 1;

            return ConfigurationVersion::create([
                'network_id' => $network->id,
                'version' => $version,
                'status' => 'GENERATED',
                'schema_version' => '1',
                'snapshot' => $snapshot,
                'snapshot_hash' => $hash,
                'generated_at' => $at,
            ]);
        });
    }

    private function activeRules(array $rules, $schedules, CarbonInterface $at): array
    {
        return collect($rules)
            ->filter(function (array $rule) use ($at, $schedules): bool {
                if (($rule['enabled'] ?? true) === false) {
                    return false;
                }

                if (!empty($rule['schedule_id'])) {
                    $schedule = $schedules->firstWhere('id', $rule['schedule_id']);

                    if (!$schedule || !$this->scheduleResolver->isActive($schedule, $at)) {
                        return false;
                    }
                }

                if (!empty($rule['starts_at']) && CarbonImmutable::parse($rule['starts_at'])->gt($at)) {
                    return false;
                }

                if (!empty($rule['expires_at']) && CarbonImmutable::parse($rule['expires_at'])->lte($at)) {
                    return false;
                }

                return true;
            })
            ->sortBy(fn (array $rule) => [
                $rule['priority'] ?? 1000,
                $rule['id'] ?? 0,
            ])
            ->values()
            ->all();
    }
}
