<?php

namespace App\Services\Configuration;

use App\Models\ConfigurationVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConfigurationLifecycleService
{
    public function validate(ConfigurationVersion $configuration): ConfigurationVersion
    {
        return DB::transaction(function () use ($configuration) {
            $configuration = ConfigurationVersion::query()->lockForUpdate()->findOrFail($configuration->id);

            if (!in_array($configuration->status, ['GENERATED', 'VALID'], true)) {
                throw new RuntimeException("Configuration {$configuration->version} cannot be validated from status {$configuration->status}.");
            }

            $configuration->update(['status' => 'VALIDATING', 'error_message' => null]);

            try {
                $snapshot = $configuration->snapshot;

                if (!is_array($snapshot)) {
                    throw new RuntimeException('Configuration snapshot is invalid.');
                }

                if (($snapshot['schema_version'] ?? null) !== (int) $configuration->schema_version) {
                    throw new RuntimeException('Configuration schema version mismatch.');
                }

                if (($snapshot['network']['id'] ?? null) !== $configuration->network_id) {
                    throw new RuntimeException('Configuration network mismatch.');
                }

                $canonical = json_encode(
                    $snapshot,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );

                if (!hash_equals($configuration->snapshot_hash, hash('sha256', $canonical))) {
                    throw new RuntimeException('Configuration snapshot hash mismatch.');
                }

                $configuration->update([
                    'status' => 'VALID',
                    'validated_at' => now(),
                    'error_message' => null,
                ]);

                return $configuration->fresh();
            } catch (\Throwable $e) {
                $configuration->update([
                    'status' => 'GENERATED',
                    'error_message' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    public function stage(ConfigurationVersion $configuration): ConfigurationVersion
    {
        return DB::transaction(function () use ($configuration) {
            $configuration = ConfigurationVersion::query()->lockForUpdate()->findOrFail($configuration->id);

            if ($configuration->status !== 'VALID') {
                throw new RuntimeException("Configuration {$configuration->version} must be VALID before staging.");
            }

            $configuration->update([
                'status' => 'STAGED',
                'staged_at' => now(),
                'error_message' => null,
            ]);

            return $configuration->fresh();
        });
    }

    public function publish(ConfigurationVersion $configuration): ConfigurationVersion
    {
        return DB::transaction(function () use ($configuration) {
            $configuration = ConfigurationVersion::query()->lockForUpdate()->findOrFail($configuration->id);

            if ($configuration->status !== 'STAGED') {
                throw new RuntimeException("Configuration {$configuration->version} must be STAGED before publishing.");
            }

            ConfigurationVersion::query()
                ->where('network_id', $configuration->network_id)
                ->where('id', '!=', $configuration->id)
                ->where('status', 'PUBLISHED')
                ->update(['status' => 'SUPERSEDED']);

            $configuration->update([
                'status' => 'PUBLISHED',
                'published_at' => now(),
                'error_message' => null,
            ]);

            return $configuration->fresh();
        });
    }
}
