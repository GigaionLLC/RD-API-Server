<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Accounts with login verification set to "email" must pass the emailed code in the admin console
 * too, not just in the RustDesk client.
 */
class ConsoleEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function emailAdmin(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'mailadmin', 'password' => 'secret12345', 'email' => 'mailadmin@example.com',
            'is_admin' => true, 'status' => User::STATUS_NORMAL, 'login_verify' => User::LOGIN_VERIFY_EMAIL,
        ], $overrides));
    }

    private function mailedCode(): string
    {
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertGreaterThan(0, count($messages));
        $body = $messages[count($messages) - 1]->getOriginalMessage()->getTextBody();
        $this->assertSame(1, preg_match('/code is: (\d{6})/', (string) $body, $m));

        return $m[1];
    }

    public function test_console_login_requires_the_emailed_code(): void
    {
        $this->emailAdmin();

        $this->post('/admin/login', ['username' => 'mailadmin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->assertGuest();

        $this->get(route('admin.2fa.challenge'))->assertOk()->assertSee('Email verification');

        $this->post(route('admin.2fa.challenge.verify'), ['code' => $this->mailedCode()])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_a_wrong_code_does_not_sign_in(): void
    {
        $this->emailAdmin();

        $this->post('/admin/login', ['username' => 'mailadmin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $code = $this->mailedCode();

        $this->post(route('admin.2fa.challenge.verify'), ['code' => $code === '000000' ? '111111' : '000000'])
            ->assertRedirect(route('admin.login'));
        $this->assertGuest();

        // The pending challenge is gone: the real code no longer works without a new sign-in.
        $this->post(route('admin.2fa.challenge.verify'), ['code' => $code])->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_sign_in_fails_closed_when_the_code_cannot_be_sent(): void
    {
        $this->emailAdmin();
        Mail::shouldReceive('raw')->andThrow(new RuntimeException('smtp down'));

        $this->post('/admin/login', ['username' => 'mailadmin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_accounts_without_email_verification_are_unchanged(): void
    {
        $this->emailAdmin(['login_verify' => User::LOGIN_VERIFY_OFF]);

        $this->post('/admin/login', ['username' => 'mailadmin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
    }
}
