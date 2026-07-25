<?php

namespace App\Console\Commands;

use App\Models\ConsoleAudit;
use App\Models\LdapIdentity;
use App\Models\User;
use App\Models\UserThird;
use App\Services\AccountCredentialService;
use App\Support\AccountPasswordPolicy;
use App\Support\GeneratedAdminPassword;
use App\Support\InitialAdminPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Question\Question;

/**
 * Recover access to an account from a shell.
 *
 * This is the break-glass path: it must work when the console is unreachable, when the identity
 * provider is wrong, and when the operator has lost their second factor. It therefore refuses
 * nothing outright, but it removes a security control only when explicitly told to, and says
 * exactly what it removed.
 *
 * Anyone able to run this already holds the database credentials, APP_KEY, and root inside the
 * container, so it grants no authority they did not have. It does make the change auditable, which
 * a hand-written UPDATE would not be.
 */
class ResetAdminPassword extends Command
{
    protected $signature = 'rustdesk:admin:reset
        {username : The account to recover}
        {--password-stdin : Read the new password from standard input}
        {--generate : Generate a strong password and print it once}
        {--unlink-federated-identities : Remove linked LDAP/SSO identities so a local password can be set}
        {--clear-force-sso : Allow this account to sign in with a local password again}
        {--clear-2fa : Remove two-factor enrollment and recovery codes}';

    protected $description = 'Reset an administrator password and, on request, restore local sign-in';

    public function handle(AccountCredentialService $credentials): int
    {
        $username = (string) $this->argument('username');

        $user = User::where('username', $username)->first();
        if ($user === null) {
            $this->error(sprintf('No account named "%s" exists.', $username));

            return self::FAILURE;
        }

        if (! $this->confirmBlockingState($user)) {
            return self::FAILURE;
        }

        $password = $this->resolvePassword($username);
        if ($password === null) {
            return self::FAILURE;
        }

        $removed = $this->clearBlockingState($user);

        try {
            $user = $credentials->replacePassword($user->refresh(), $password);
        } catch (ValidationException $exception) {
            $this->error((string) collect($exception->errors())->flatten()->first());

            return self::FAILURE;
        }

        // The credential file only ever backs the account it was generated for, and a fresh
        // password makes it wrong as well as unnecessary.
        InitialAdminPassword::forget();

        $this->report($user, $removed);
        $this->audit($user, $removed);

        return self::SUCCESS;
    }

    /**
     * Refuse to proceed while something would silently defeat the reset.
     *
     * Stripping a federated link, an SSO requirement, or a second factor without being asked would
     * be a security downgrade the operator never requested, so each needs its own flag.
     */
    private function confirmBlockingState(User $user): bool
    {
        $missing = [];

        if ($this->hasFederatedIdentity($user) && ! $this->option('unlink-federated-identities')) {
            $missing[] = 'this account has a linked LDAP or SSO identity and cannot hold a local password: pass --unlink-federated-identities';
        }

        if ($user->force_sso && ! $this->option('clear-force-sso')) {
            $missing[] = 'this account is restricted to SSO sign-in: pass --clear-force-sso';
        }

        foreach ($missing as $reason) {
            $this->error($reason);
        }

        return $missing === [];
    }

    /**
     * @return list<string> what was removed, for the operator and the audit record
     */
    private function clearBlockingState(User $user): array
    {
        $removed = [];

        if ($this->option('unlink-federated-identities') && $this->hasFederatedIdentity($user)) {
            $ldap = LdapIdentity::where('user_id', $user->id)->delete();
            $third = UserThird::where('user_id', $user->id)->delete();
            $removed[] = sprintf('unlinked %d LDAP and %d SSO identity record(s)', $ldap, $third);
        }

        if ($this->option('clear-force-sso') && $user->force_sso) {
            $user->forceFill(['force_sso' => false])->save();
            $removed[] = 'cleared the SSO-only restriction';
        }

        if ($this->option('clear-2fa')) {
            $user->forceFill([
                'two_factor_enabled' => false,
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes' => null,
                'login_verify' => User::LOGIN_VERIFY_OFF,
            ])->save();
            $removed[] = 'removed two-factor enrollment and recovery codes';
        }

        return $removed;
    }

    private function hasFederatedIdentity(User $user): bool
    {
        return LdapIdentity::where('user_id', $user->id)->exists()
            || UserThird::where('user_id', $user->id)->exists();
    }

    private function resolvePassword(string $username): ?string
    {
        if ($this->option('generate')) {
            $password = GeneratedAdminPassword::create($username);
            $this->newLine();
            $this->line('  Generated password: <options=bold>'.$password.'</>');
            $this->newLine();

            return $password;
        }

        $password = $this->option('password-stdin')
            ? $this->readPasswordFromStdin()
            : $this->promptForPassword();

        if ($password === null) {
            return null;
        }

        try {
            AccountPasswordPolicy::assertValid($password);
        } catch (ValidationException $exception) {
            $this->error((string) collect($exception->errors())->flatten()->first());

            return null;
        }

        return $password;
    }

    private function readPasswordFromStdin(): ?string
    {
        $stream = fopen('php://stdin', 'rb');
        if ($stream === false) {
            $this->error('Could not read the password from standard input.');

            return null;
        }

        if (stream_isatty($stream)) {
            fclose($stream);
            // A tty here means nothing was piped in and the command would silently block.
            $this->error('--password-stdin expects piped input; omit it to be prompted instead.');

            return null;
        }

        $password = (string) stream_get_contents($stream, AccountPasswordPolicy::MAX_LENGTH * 4);
        fclose($stream);

        return rtrim($password, "\r\n");
    }

    private function promptForPassword(): ?string
    {
        try {
            $password = $this->askHidden('New password');
            $confirmation = $this->askHidden('Confirm password');
        } catch (ConsoleRuntimeException) {
            $this->error('Secure password prompting is unavailable here. Use --password-stdin or --generate.');

            return null;
        }

        if (! is_string($password) || ! is_string($confirmation)) {
            $this->error('Password input was not received.');

            return null;
        }

        if (! hash_equals($password, $confirmation)) {
            $this->error('The password confirmation does not match.');

            return null;
        }

        return $password;
    }

    private function askHidden(string $label): mixed
    {
        // Not trimmable: a leading or trailing space is a legitimate part of a password, and
        // silently stripping it would set a credential the operator cannot reproduce.
        $question = (new Question($label))
            ->setHidden(true)
            ->setHiddenFallback(false)
            ->setTrimmable(false);

        $answer = $this->output->askQuestion($question);

        return is_string($answer) ? rtrim($answer, "\r\n") : $answer;
    }

    /**
     * @param  list<string>  $removed
     */
    private function report(User $user, array $removed): void
    {
        $this->info(sprintf('Password reset for "%s".', $user->username));

        foreach ($removed as $entry) {
            $this->warn('  - '.$entry);
        }

        // replacePassword() revokes these as a side effect. Silence would leave the operator
        // wondering later why their automation stopped working.
        $this->line('  Existing sessions, client tokens, API keys and deploy tokens for this account were revoked.');
    }

    /**
     * @param  list<string>  $removed
     */
    private function audit(User $user, array $removed): void
    {
        // A credential change made from a shell is invisible today: the console audit trail is
        // written by HTTP middleware only.
        try {
            ConsoleAudit::create([
                'user_id' => $user->id,
                'method' => 'CLI',
                'route_name' => 'cli.admin.password-reset',
                'path' => 'artisan rustdesk:admin:reset',
                'ip' => null,
            ]);
        } catch (\Throwable $e) {
            $this->warn('  The audit record could not be written: '.$e->getMessage());
        }

        Log::warning('Administrator password reset from the CLI', [
            'user_id' => $user->id,
            'username' => $user->username,
            'removed' => $removed,
            'system_user' => getenv('SUDO_USER') ?: getenv('USER') ?: null,
        ]);
    }
}
