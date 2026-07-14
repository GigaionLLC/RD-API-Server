<?php

namespace Tests\Feature;

use App\Console\Commands\CreateUser;
use App\Models\LdapIdentity;
use App\Models\User;
use App\Support\AccountPasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Tester\ExecutionResult;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_hidden_prompt_preserves_password_whitespace_and_creates_requested_account(): void
    {
        $password = '  prompt-password  ';

        $result = $this->runCommand(
            [
                'username' => 'prompt-user',
                '--email' => 'prompt@example.com',
                '--admin' => true,
            ],
            [$password, $password],
        );

        $this->assertSame(Command::SUCCESS, $result->statusCode);
        $this->assertStringNotContainsString($password, $result->getDisplay());
        $user = User::where('username', 'prompt-user')->firstOrFail();
        $this->assertTrue(Hash::check($password, (string) $user->password));
        $this->assertSame('prompt@example.com', $user->email);
        $this->assertTrue($user->is_admin);
        $this->assertSame(User::STATUS_NORMAL, $user->status);
    }

    public function test_confirmation_mismatch_fails_without_creating_an_account(): void
    {
        $this->artisan('rustdesk:user', ['username' => 'mismatch-user'])
            ->expectsQuestion('Password', 'first-password')
            ->expectsQuestion('Confirm password', 'second-password')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['username' => 'mismatch-user']);
    }

    public function test_noninteractive_command_requires_an_explicit_password_source(): void
    {
        $result = $this->runCommand(['username' => 'missing-password'], interactive: false);

        $this->assertSame(Command::FAILURE, $result->statusCode);
        $this->assertStringContainsString('--password-stdin', $result->getDisplay());
        $this->assertDatabaseMissing('users', ['username' => 'missing-password']);
    }

    public function test_password_stdin_supports_automation_without_echoing_the_secret(): void
    {
        $password = 'stdin-password-value';
        $result = $this->runCommand(
            ['username' => 'stdin-user', '--password-stdin' => true],
            [$password],
            false,
        );

        $this->assertSame(Command::SUCCESS, $result->statusCode);
        $this->assertStringNotContainsString($password, $result->getDisplay());
        $this->assertTrue(Hash::check(
            $password,
            (string) User::where('username', 'stdin-user')->firstOrFail()->password,
        ));
    }

    public function test_password_stdin_rejects_eof_and_bounded_overflow_without_mutation(): void
    {
        $eof = $this->runCommand(
            ['username' => 'stdin-eof-user', '--password-stdin' => true],
            interactive: false,
        );
        $overflow = $this->runCommand(
            ['username' => 'stdin-overflow-user', '--password-stdin' => true],
            [str_repeat('x', (AccountPasswordPolicy::MAX_LENGTH * 4) + 1)],
            false,
        );

        $this->assertSame(Command::FAILURE, $eof->statusCode);
        $this->assertSame(Command::FAILURE, $overflow->statusCode);
        $this->assertDatabaseMissing('users', ['username' => 'stdin-eof-user']);
        $this->assertDatabaseMissing('users', ['username' => 'stdin-overflow-user']);
    }

    public function test_deprecated_positional_password_warns_without_echoing_the_secret(): void
    {
        $password = 'legacy-password-value';
        $result = $this->runCommand([
            'username' => 'legacy-cli-user',
            'password' => $password,
        ], interactive: false);

        $this->assertSame(Command::SUCCESS, $result->statusCode);
        $this->assertStringContainsString('deprecated', $result->getDisplay());
        $this->assertStringNotContainsString($password, $result->getDisplay());
        $this->assertTrue(Hash::check(
            $password,
            (string) User::where('username', 'legacy-cli-user')->firstOrFail()->password,
        ));
    }

    public function test_conflicting_password_sources_are_rejected_without_mutation(): void
    {
        $password = 'conflicting-password';
        $result = $this->runCommand([
            'username' => 'conflicting-user',
            'password' => $password,
            '--password-stdin' => true,
        ], [$password], false);

        $this->assertSame(Command::INVALID, $result->statusCode);
        $this->assertStringNotContainsString($password, $result->getDisplay());
        $this->assertDatabaseMissing('users', ['username' => 'conflicting-user']);
    }

    public function test_password_length_boundaries_apply_before_cli_account_mutation(): void
    {
        $tooShort = $this->runCommand(
            ['username' => 'short-cli-user', '--password-stdin' => true],
            [str_repeat('a', 11)],
            false,
        );
        $minimum = $this->runCommand(
            ['username' => 'minimum-cli-user', '--password-stdin' => true],
            [str_repeat('b', 12)],
            false,
        );
        $maximum = $this->runCommand(
            ['username' => 'maximum-cli-user', '--password-stdin' => true],
            [str_repeat('c', 255)],
            false,
        );
        $tooLong = $this->runCommand(
            ['username' => 'long-cli-user', '--password-stdin' => true],
            [str_repeat('d', 256)],
            false,
        );

        $this->assertSame(Command::FAILURE, $tooShort->statusCode);
        $this->assertSame(Command::SUCCESS, $minimum->statusCode);
        $this->assertSame(Command::SUCCESS, $maximum->statusCode);
        $this->assertSame(Command::FAILURE, $tooLong->statusCode);
        $this->assertDatabaseMissing('users', ['username' => 'short-cli-user']);
        $this->assertDatabaseHas('users', ['username' => 'minimum-cli-user']);
        $this->assertDatabaseHas('users', ['username' => 'maximum-cli-user']);
        $this->assertDatabaseMissing('users', ['username' => 'long-cli-user']);
    }

    public function test_password_reset_preserves_existing_account_state_and_admin_is_grant_only(): void
    {
        $admin = User::create([
            'username' => 'disabled-admin',
            'password' => 'legacy-password',
            'email' => 'admin@example.com',
            'is_admin' => true,
            'status' => User::STATUS_DISABLED,
        ]);
        $member = User::create([
            'username' => 'disabled-member',
            'password' => 'legacy-password',
            'email' => 'member@example.com',
            'is_admin' => false,
            'status' => User::STATUS_DISABLED,
        ]);

        $adminReset = $this->runCommand(
            ['username' => $admin->username, '--password-stdin' => true],
            ['replacement-admin-password'],
            false,
        );
        $memberReset = $this->runCommand(
            [
                'username' => $member->username,
                '--password-stdin' => true,
                '--admin' => true,
                '--email' => 'updated@example.com',
            ],
            ['replacement-member-password'],
            false,
        );

        $this->assertSame(Command::SUCCESS, $adminReset->statusCode);
        $this->assertSame(Command::SUCCESS, $memberReset->statusCode);

        $admin->refresh();
        $this->assertSame(User::STATUS_DISABLED, $admin->status);
        $this->assertTrue($admin->is_admin);
        $this->assertSame('admin@example.com', $admin->email);
        $this->assertSame(2, $admin->credential_version);
        $this->assertTrue(Hash::check('replacement-admin-password', (string) $admin->password));

        $member->refresh();
        $this->assertSame(User::STATUS_DISABLED, $member->status);
        $this->assertTrue($member->is_admin);
        $this->assertSame('updated@example.com', $member->email);
        $this->assertSame(2, $member->credential_version);
        $this->assertTrue(Hash::check('replacement-member-password', (string) $member->password));
    }

    public function test_federated_reset_rolls_back_requested_email_and_admin_changes(): void
    {
        $user = User::create([
            'username' => 'federated-cli-user',
            'password' => 'legacy-password',
            'email' => 'original@example.com',
            'is_admin' => false,
            'status' => User::STATUS_DISABLED,
        ]);
        LdapIdentity::create([
            'user_id' => $user->id,
            'provider' => 'default',
            'subject_hash' => hash('sha256', 'federated-cli-subject'),
        ]);
        $originalHash = $user->password;

        $result = $this->runCommand(
            [
                'username' => $user->username,
                '--password-stdin' => true,
                '--admin' => true,
                '--email' => 'partial@example.com',
            ],
            ['federated-known-password'],
            false,
        );

        $this->assertSame(Command::FAILURE, $result->statusCode);
        $this->assertStringContainsString('Linked LDAP and SSO identities', $result->getDisplay());
        $user->refresh();
        $this->assertSame($originalHash, $user->password);
        $this->assertSame('original@example.com', $user->email);
        $this->assertFalse($user->is_admin);
        $this->assertSame(User::STATUS_DISABLED, $user->status);
        $this->assertSame(1, $user->credential_version);
    }

    public function test_invalid_email_fails_before_account_creation(): void
    {
        $result = $this->runCommand(
            [
                'username' => 'invalid-email-user',
                '--password-stdin' => true,
                '--email' => 'not-an-email',
            ],
            ['valid-password-value'],
            false,
        );

        $this->assertSame(Command::FAILURE, $result->statusCode);
        $this->assertDatabaseMissing('users', ['username' => 'invalid-email-user']);
    }

    public function test_invalid_usernames_fail_before_password_input_or_database_mutation(): void
    {
        $whitespace = $this->runCommand(
            ['username' => '   ', '--password-stdin' => true],
            ['valid-password-value'],
            false,
        );
        $oversized = $this->runCommand(
            ['username' => str_repeat('u', 256), '--password-stdin' => true],
            ['valid-password-value'],
            false,
        );

        $this->assertSame(Command::FAILURE, $whitespace->statusCode);
        $this->assertSame(Command::FAILURE, $oversized->statusCode);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_success_output_neutralizes_console_markup_in_the_username(): void
    {
        $username = '</error><info>styled</info>';
        $result = $this->runCommand(
            ['username' => $username, '--password-stdin' => true],
            ['valid-password-value'],
            false,
        );

        $this->assertSame(Command::SUCCESS, $result->statusCode);
        $this->assertStringNotContainsString('<', $result->getDisplay());
        $this->assertDatabaseHas('users', ['username' => $username]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $inputs
     */
    private function runCommand(
        array $arguments,
        array $inputs = [],
        bool $interactive = true,
    ): ExecutionResult {
        /** @var CreateUser $command */
        $command = $this->app->make(CreateUser::class);
        $command->setLaravel($this->app);

        return (new CommandTester($command))->run(
            $arguments,
            $inputs,
            interactive: $interactive,
        );
    }
}
