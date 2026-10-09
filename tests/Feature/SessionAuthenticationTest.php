<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_available_to_guests(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in to SAIFNEX')
            ->assertSee('Self-service registration is not enabled');
    }

    public function test_valid_credentials_authenticate_and_redirect_to_workspace(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@example.com',
            'password' => 'Strong-password-123!',
        ]);

        $this->post('/login', [
            'email' => 'operator@example.com',
            'password' => 'Strong-password-123!',
        ])->assertRedirect(route('workspace'));

        $this->assertAuthenticatedAs($user);
        $this->get('/workspace')->assertOk()->assertSee('PREVIEW MODE');
    }

    public function test_invalid_credentials_do_not_authenticate(): void
    {
        User::factory()->create([
            'email' => 'operator@example.com',
            'password' => 'Strong-password-123!',
        ]);

        $this->from('/login')->post('/login', [
            'email' => 'operator@example.com',
            'password' => 'incorrect-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_workspace_requires_an_authenticated_session(): void
    {
        $this->get('/workspace')
            ->assertRedirect('/login');
    }

    public function test_logout_ends_the_authenticated_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}
