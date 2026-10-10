<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    public function test_homepage_displays_saifnex_public_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('SAIFNEX')
            ->assertSee('Network Intelligence')
            ->assertSee('Own every decision.');
    }

    public function test_control_center_preview_is_available_and_discloses_demo_state(): void
    {
        $this->get('/control-center')
            ->assertOk()
            ->assertSee('CONTROL CENTER', false)
            ->assertSee('PREVIEW MODE')
            ->assertSee('not connected to live networks')
            ->assertSee('Sign-in is available, but live metrics and network management actions are not connected in this preview.');
    }
    public function test_security_page_explains_current_and_planned_safeguards(): void
    {
        $this->get('/security')
            ->assertOk()
            ->assertSee('Security by design.')
            ->assertSee('Signed configuration')
            ->assertSee('DNS traffic enforcement')
            ->assertSee('not a security certification');
    }

}
