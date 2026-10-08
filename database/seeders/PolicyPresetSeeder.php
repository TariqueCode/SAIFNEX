<?php

namespace Database\Seeders;

use App\Models\PolicyProfile;
use Illuminate\Database\Seeder;

class PolicyPresetSeeder extends Seeder
{
    public function run(): void
    {
        $presets = [
            'OPEN' => [
                'name' => 'Open / Unrestricted',
                'description' => 'Minimal policy restrictions.',
                'rules' => [],
            ],
            'FAMILY' => [
                'name' => 'Family',
                'description' => 'Balanced protection for shared family networks.',
                'rules' => [
                    ['target_type' => 'CATEGORY', 'target' => 'ADULT', 'action' => 'BLOCK', 'priority' => 100],
                    ['target_type' => 'CATEGORY', 'target' => 'MALWARE', 'action' => 'BLOCK', 'priority' => 50],
                    ['target_type' => 'CATEGORY', 'target' => 'PHISHING', 'action' => 'BLOCK', 'priority' => 50],
                ],
            ],
            'KIDS' => [
                'name' => 'Kids',
                'description' => 'Stricter protection for children.',
                'rules' => [
                    ['target_type' => 'CATEGORY', 'target' => 'ADULT', 'action' => 'BLOCK', 'priority' => 50],
                    ['target_type' => 'CATEGORY', 'target' => 'GAMBLING', 'action' => 'BLOCK', 'priority' => 50],
                    ['target_type' => 'CATEGORY', 'target' => 'MALWARE', 'action' => 'BLOCK', 'priority' => 25],
                    ['target_type' => 'CATEGORY', 'target' => 'PHISHING', 'action' => 'BLOCK', 'priority' => 25],
                ],
            ],
            'SCHOOL' => [
                'name' => 'School',
                'description' => 'Education-focused network protection.',
                'rules' => [
                    ['target_type' => 'CATEGORY', 'target' => 'MALWARE', 'action' => 'BLOCK', 'priority' => 25],
                    ['target_type' => 'CATEGORY', 'target' => 'PHISHING', 'action' => 'BLOCK', 'priority' => 25],
                    ['target_type' => 'CATEGORY', 'target' => 'ADULT', 'action' => 'BLOCK', 'priority' => 50],
                ],
            ],
            'OFFICE' => [
                'name' => 'Office',
                'description' => 'Workplace protection baseline.',
                'rules' => [
                    ['target_type' => 'CATEGORY', 'target' => 'MALWARE', 'action' => 'BLOCK', 'priority' => 25],
                    ['target_type' => 'CATEGORY', 'target' => 'PHISHING', 'action' => 'BLOCK', 'priority' => 25],
                ],
            ],
            'STUDY' => [
                'name' => 'Study',
                'description' => 'Focus-oriented baseline for study time.',
                'rules' => [
                    ['target_type' => 'CATEGORY', 'target' => 'MALWARE', 'action' => 'BLOCK', 'priority' => 25],
                    ['target_type' => 'CATEGORY', 'target' => 'PHISHING', 'action' => 'BLOCK', 'priority' => 25],
                ],
            ],
            'WORK' => [
                'name' => 'Work',
                'description' => 'Work-focused protection baseline.',
                'rules' => [
                    ['target_type' => 'CATEGORY', 'target' => 'MALWARE', 'action' => 'BLOCK', 'priority' => 25],
                    ['target_type' => 'CATEGORY', 'target' => 'PHISHING', 'action' => 'BLOCK', 'priority' => 25],
                ],
            ],
            'PRIVACY' => [
                'name' => 'Privacy',
                'description' => 'Privacy-focused policy baseline.',
                'rules' => [
                    ['target_type' => 'CATEGORY', 'target' => 'TRACKERS', 'action' => 'BLOCK', 'priority' => 100],
                    ['target_type' => 'CATEGORY', 'target' => 'MALWARE', 'action' => 'BLOCK', 'priority' => 25],
                    ['target_type' => 'CATEGORY', 'target' => 'PHISHING', 'action' => 'BLOCK', 'priority' => 25],
                ],
            ],
            'MAXIMUM_SECURITY' => [
                'name' => 'Maximum Security',
                'description' => 'Strict security baseline.',
                'rules' => [
                    ['target_type' => 'CATEGORY', 'target' => 'MALWARE', 'action' => 'BLOCK', 'priority' => 10],
                    ['target_type' => 'CATEGORY', 'target' => 'PHISHING', 'action' => 'BLOCK', 'priority' => 10],
                    ['target_type' => 'CATEGORY', 'target' => 'ADULT', 'action' => 'BLOCK', 'priority' => 50],
                    ['target_type' => 'CATEGORY', 'target' => 'GAMBLING', 'action' => 'BLOCK', 'priority' => 50],
                ],
            ],
        ];

        foreach ($presets as $key => $preset) {
            $template = PolicyProfile::updateOrCreate(
                [
                    'network_id' => null,
                    'preset_key' => $key,
                    'is_template' => true,
                ],
                [
                    'name' => $preset['name'],
                    'key' => 'saifnex-'.$key,
                    'source' => 'SAIFNEX',
                    'status' => 'ACTIVE',
                    'description' => $preset['description'],
                    'is_default' => false,
                    'is_template' => true,
                    'template_profile_id' => null,
                ]
            );

            $template->rules()->delete();

            foreach ($preset['rules'] as $rule) {
                $template->rules()->create($rule);
            }
        }
    }
}
