<?php

namespace App\Services\Policy;

use App\Models\PolicyProfile;
use App\Models\PolicyVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PolicyVersionService
{
    public function publish(PolicyProfile $profile, ?int $userId = null): PolicyVersion
    {
        return DB::transaction(function () use ($profile, $userId) {
            $profile->load(['rules', 'network']);

            if ($profile->status === 'ACTIVE') {
                throw new RuntimeException('An active policy must be changed through a new draft profile.');
            }

            $this->validate($profile);

            $nextVersion = ((int) $profile->versions()->max('version')) + 1;

            $snapshot = [
                'schema_version' => 1,
                'profile' => [
                    'id' => $profile->id,
                    'network_id' => $profile->network_id,
                    'name' => $profile->name,
                    'key' => $profile->key,
                ],
                'rules' => $profile->rules
                    ->sortBy(fn ($rule) => [$rule->priority, $rule->id])
                    ->values()
                    ->map(fn ($rule) => [
                        'id' => $rule->id,
                        'schedule_id' => $rule->schedule_id,
                        'target_type' => $rule->target_type,
                        'target' => $rule->target,
                        'action' => $rule->action,
                        'priority' => $rule->priority,
                        'enabled' => $rule->enabled,
                        'starts_at' => $rule->starts_at?->toIso8601String(),
                        'expires_at' => $rule->expires_at?->toIso8601String(),
                    ])->all(),
            ];

            $canonical = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $hash = hash('sha256', $canonical);

            $version = $profile->versions()->create([
                'version' => $nextVersion,
                'status' => 'ACTIVE',
                'snapshot' => $snapshot,
                'snapshot_hash' => $hash,
                'created_by' => $userId,
                'published_at' => now(),
            ]);

            $profile->update(['status' => 'ACTIVE']);

            $profile->versions()
                ->whereKeyNot($version->id)
                ->where('status', 'ACTIVE')
                ->update(['status' => 'SUPERSEDED']);

            return $version->fresh();
        });
    }

    public function rollback(PolicyProfile $profile, int $versionNumber, ?int $userId = null): PolicyVersion
    {
        return DB::transaction(function () use ($profile, $versionNumber, $userId) {
            $source = $profile->versions()
                ->where('version', $versionNumber)
                ->whereIn('status', ['ACTIVE', 'SUPERSEDED'])
                ->firstOrFail();

            $nextVersion = ((int) $profile->versions()->max('version')) + 1;

            $snapshot = $source->snapshot;
            $canonical = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $version = $profile->versions()->create([
                'version' => $nextVersion,
                'status' => 'ACTIVE',
                'snapshot' => $snapshot,
                'snapshot_hash' => hash('sha256', $canonical),
                'created_by' => $userId,
                'published_at' => now(),
            ]);

            $profile->update(['status' => 'ACTIVE']);

            $profile->versions()
                ->whereKeyNot($version->id)
                ->where('status', 'ACTIVE')
                ->update(['status' => 'SUPERSEDED']);

            return $version->fresh();
        });
    }

    private function validate(PolicyProfile $profile): void
    {
        $rules = $profile->rules;

        foreach ($rules as $rule) {
            if ($rule->starts_at && $rule->expires_at && $rule->expires_at->lt($rule->starts_at)) {
                throw new RuntimeException("Rule {$rule->id} has an invalid time window.");
            }

            if (!Str::length(trim($rule->target))) {
                throw new RuntimeException("Rule {$rule->id} has an empty target.");
            }

            if (!in_array($rule->action, [
                'ALLOW', 'BLOCK', 'WARN', 'MONITOR', 'DIRECT', 'ROUTE', 'CACHE',
            ], true)) {
                throw new RuntimeException("Rule {$rule->id} uses an unsupported action.");
            }
        }
    }
}
