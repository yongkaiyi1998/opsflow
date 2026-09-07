<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use App\UserStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertSee('Sign in')->assertSee('Forgot your password?');
    }

    #[TestWith(['ADMIN'])]
    #[TestWith(['FINANCE'])]
    #[TestWith(['EMPLOYEE'])]
    public function test_active_users_can_sign_in_and_access_the_shell(string $role): void
    {
        $user = User::factory()->create(['role' => UserRole::from($role), 'name' => '<script>alert(1)</script>']);
        $oldSession = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 1])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        $this->get('/dashboard')->assertSee('Your account')->assertSee($user->name)->assertDontSee($user->name, false);
    }

    public function test_invalid_credentials_and_inactive_accounts_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'status' => 'ACTIVE'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_failed_logins_are_rate_limited_even_with_a_correct_password_afterwards(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors(['email' => __('auth.throttle', ['seconds' => 60, 'minutes' => 1])]);
        $this->assertGuest();
    }

    public function test_login_requires_valid_fields(): void
    {
        $this->post('/login', [])->assertSessionHasErrors(['email', 'password']);
        $this->post('/login', ['email' => 'invalid', 'password' => 'password', 'remember' => 'invalid'])->assertSessionHasErrors(['email', 'remember']);
        $this->assertGuest();
    }

    public function test_deactivation_ends_an_existing_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['private_data' => 'secret']);
        $user->status = UserStatus::Inactive;
        $user->save();

        $this->get('/dashboard')->assertRedirect('/login')->assertSessionMissing('private_data')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_inactive_json_access_is_forbidden(): void
    {
        $this->actingAs(User::factory()->inactive()->create())->getJson('/dashboard')->assertForbidden();
        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $this->actingAs(User::factory()->create())->withSession(['private_data' => 'secret']);

        $this->post('/logout')->assertRedirect('/login')->assertSessionMissing('private_data');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_registration_is_not_publicly_available(): void
    {
        $this->post('/register', ['role' => 'ADMIN'])->assertNotFound();
    }
}
