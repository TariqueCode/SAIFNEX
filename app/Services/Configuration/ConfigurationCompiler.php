<?php

namespace App\Services\Configuration;

use App\Models\ConfigurationVersion;
use App\Models\Network;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ConfigurationCompiler
{
    public function __construct(
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

                        if ($assignment->expires_at && $assignment->expires_at->lte($at)) {
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
                    'rules' => $this->compileRules($version->snapshot['rules'] ?? []),
                    'schedules' => $this->compileSchedules($network->schedules),
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

    /**
     * Keep time-bound rules in the signed snapshot so the node can enforce
     * schedule and expiry boundaries without waiting for a new deployment.
     */
    private function compileRules(array $rules): array
    {
        return collect($rules)
            ->sortBy(fn (array $rule) => [
                $rule['priority'] ?? 1000,
                $rule['id'] ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * Include the exact schedule definitions referenced by runtime rules.
     * The full definitions are signed with the same configuration snapshot.
     */
    private function compileSchedules($schedules): array
    {
        $compiled = [];

        foreach ($schedules as $schedule) {
            $compiled[(string) $schedule->id] = [
                'timezone' => $schedule->timezone,
                'definition' => $schedule->definition,
                'enabled' => (bool) $schedule->enabled,
            ];
        }

        ksort($compiled, SORT_NATURAL);

        return $compiled;
    }
}
