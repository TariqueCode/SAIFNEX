<?php

namespace App\Services\Policy;

use App\Models\Network;
use App\Models\PolicyProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PresetPolicyService
{
    public const PRESETS = [
        'OPEN',
        'FAMILY',
        'KIDS',
        'SCHOOL',
        'OFFICE',
        'STUDY',
        'WORK',
        'PRIVACY',
        'MAXIMUM_SECURITY',
    ];

    public function createFromPreset(
        Network $network,
        string $presetKey,
        string $name,
        ?int $createdBy = null,
    ): PolicyProfile {
        $presetKey = strtoupper($presetKey);

        if (!in_array($presetKey, self::PRESETS, true)) {
            throw ValidationException::withMessages([
                'preset_key' => 'Unsupported SAIFNEX preset.',
            ]);
        }

        $template = PolicyProfile::query()
            ->whereNull('network_id')
            ->where('is_template', true)
            ->where('preset_key', $presetKey)
            ->with('rules')
            ->first();

        if (!$template) {
            throw ValidationException::withMessages([
                'preset_key' => 'This SAIFNEX preset has not been provisioned yet.',
            ]);
        }

        return DB::transaction(function () use ($network, $template, $presetKey, $name) {
            $profile = $network->policyProfiles()->create([
                'name' => $name,
                'key' => $this->uniqueKey($network, $name),
                'preset_key' => $presetKey,
                'template_profile_id' => $template->id,
                'source' => 'PRESET',
                'status' => 'DRAFT',
                'is_default' => false,
                'is_template' => false,
            ]);

            foreach ($template->rules as $rule) {
                $profile->rules()->create([
                    'target_type' => $rule->target_type,
                    'target' => $rule->target,
                    'action' => $rule->action,
                    'priority' => $rule->priority,
                    'enabled' => $rule->enabled,
                    'starts_at' => $rule->starts_at,
                    'expires_at' => $rule->expires_at,
                    'schedule_id' => $rule->schedule_id,
                ]);
            }

            return $profile->load('rules');
        });
    }

    private function uniqueKey(Network $network, string $name): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-')) ?: 'policy';
        $key = $base;
        $counter = 2;

        while ($network->policyProfiles()->where('key', $key)->exists()) {
            $key = $base.'-'.$counter++;
        }

        return $key;
    }
}
