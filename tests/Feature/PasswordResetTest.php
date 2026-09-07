<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use App\UserStatus;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_password_recovery_forms_render(): void
    {
        $this->withoutVite();
        $this->get('/forgot-password')->assertSee('Send reset link');
        $this->get('/reset-password/test-token?email=user@example.com')->assertSee('user@example.com')->assertSee('Confirm password');
    }

    public function test_active_user_receives_a_working_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $rememberToken = $user->remember_token;

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $payload = ['email' => $user->email, 'token' => $notification->token, 'password' => 'new-password', 'password_confirmation' => 'new-password', 'role' => 'ADMIN'];
            $this->post('/reset-password', $payload)->assertRedirect('/login');
            $this->post('/reset-password', $payload)->assertSessionHasErrors('email');

            return true;
        });
        $user->refresh();
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertNotSame($rememberToken, $user->remember_token);
        $this->assertSame(UserRole::Employee, $user->role);
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'new-password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_unknown_and_inactive_emails_do_not_receive_links_or_reveal_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->inactive()->create();
        $message = 'If an active account matches that email, a password reset link will be sent.';

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', $message);
        $this->post('/forgot-password', ['email' => 'missing@example.com'])->assertSessionHas('status', $message);

        Notification::assertNothingSent();
    }

    #[TestWith(['invalid'])]
    #[TestWith(['expired'])]
    #[TestWith(['inactive'])]
    public function test_unusable_reset_tokens_do_not_change_passwords(string $scenario): void
    {
        $user = User::factory()->create();
        $hash = $user->password;
        $token = Password::createToken($user);
        if ($scenario === 'invalid') {
            $token = 'invalid-token';
        } elseif ($scenario === 'expired') {
            $this->travel(61)->minutes();
        } else {
            $user->status = UserStatus::Inactive;
            $user->save();
        }

        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertSessionHasErrors('email');

        $this->assertSame($hash, $user->refresh()->password);
        $this->assertGuest();
    }

    public function test_reset_validates_required_fields_and_password_confirmation(): void
    {
        $this->post('/forgot-password', [])->assertSessionHasErrors('email');
        $this->post('/reset-password', [])->assertSessionHasErrors(['email', 'token', 'password']);
        $this->post('/reset-password', ['email' => 'invalid', 'token' => 'token', 'password' => 'short', 'password_confirmation' => 'different'])->assertSessionHasErrors(['email', 'password']);
    }

    public function test_password_reset_requests_are_throttled(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/forgot-password', ['email' => 'missing@example.com'])->assertRedirect();
        }

        $this->post('/forgot-password', ['email' => 'missing@example.com'])->assertTooManyRequests();
    }
}
