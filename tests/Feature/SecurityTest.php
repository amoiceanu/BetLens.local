<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_added_and_php_version_is_hidden(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('X-Powered-By');
    }

    public function test_admin_requires_a_configured_password_hash(): void
    {
        config(['services.betlens.admin_password_hash' => null]);

        $this->post(route('admin.authenticate'), ['password' => 'anything'])
            ->assertStatus(503);
    }

    public function test_admin_authentication_uses_the_hash_and_supports_logout(): void
    {
        config(['services.betlens.admin_password_hash' => Hash::make('a-secure-password')]);

        $this->post(route('admin.authenticate'), ['password' => 'a-secure-password'])
            ->assertRedirect(route('admin'))
            ->assertSessionHas('betlens_admin', true);

        $this->get(route('admin'))->assertOk();

        $this->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'))
            ->assertSessionMissing('betlens_admin');
    }

    public function test_admin_login_is_rate_limited(): void
    {
        config(['services.betlens.admin_password_hash' => Hash::make('a-secure-password')]);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('admin.authenticate'), ['password' => 'wrong-password'])
                ->assertRedirect();
        }

        $this->post(route('admin.authenticate'), ['password' => 'wrong-password'])
            ->assertStatus(429);
    }

    public function test_admin_routes_redirect_unauthenticated_visitors(): void
    {
        $this->get(route('admin'))->assertRedirect(route('admin.login'));
    }
}
