<?php

namespace Tests\Unit\Services\Policy;

use App\Models\Network;
use App\Models\PolicyProfile;
use App\Models\User;
use App\Services\Policy\PresetPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresetPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preset_creates_an_independent_customizable_profile(): void
    {
        $this->seed(\Database\Seeders\PolicyPresetSeeder::class);

        $user = User::factory()->create();
        $network = Network::create([
            'owner_id' => $user->id,
            'name' => 'Preset Test',
            'type' => 'HOME',
            'status' => 'ACTIVE',
            'timezone' => 'Asia/Dhaka',
        ]);

        $profile = app(PresetPolicyService::class)->createFromPreset(
            $network,
            'FAMILY',
            'My Family',
            $user->id,
        );

        $this->assertSame('FAMILY', $profile->preset_key);
        $this->assertFalse($profile->is_template);
        $this->assertNotNull($profile->template_profile_id);
        $this->assertCount(3, $profile->rules);

        $profile->rules()->first()->update(['action' => 'ALLOW']);

        $template = PolicyProfile::find($profile->template_profile_id);

        $this->assertSame('BLOCK', $template->rules()->first()->action);
    }
}
