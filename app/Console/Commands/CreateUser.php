<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountCredentialService;
use App\Support\AccountPasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Create (or update the password of) a user from the CLI.
 *
 * Passwords are collected through a hidden confirmation prompt or --password-stdin.
 * Positional password input remains temporarily available for backwards compatibility.
 */
class CreateUser extends Command
{
    protected $signature = 'rustdesk:user
        {username : The username}
        {password? : Deprecated: a password exposed to shell history and process listings}
        {--password-stdin : Read the password from standard input}
        {--email= : Optional email address}
        {--admin : Grant administrator privileges without revoking existing privileges}';

    protected $description = 'Create a user (or reset its password) for the admin console / client API';

    public function handle(AccountCredentialService $credentials): int
    {
        $username = (string) $this->argument('username');

        try {
            Validator::make(
                ['username' => $username],
                ['username' => ['required', 'string', 'max:255']],
            )->validate();
        } catch (ValidationException $exception) {
            $this->error($this->firstValidationError($exception));

            return self::FAILURE;
        }

        if (preg_match('/\S/u', $username) !== 1) {
            $this->error('The username must contain at least one non-whitespace character.');

            return self::FAILURE;
        }

        [$password, $passwordExitCode] = $this->resolvePassword();
        if ($passwordExitCode !== null) {
            return $passwordExitCode;
        }

        if ($password === null) {
            return self::FAILURE;
        }

        $emailProvided = $this->option('email') !== null;
        $email = $emailProvided && $this->option('email') !== ''
            ? (string) $this->option('email')
            : null;
        $grantAdmin = (bool) $this->option('admin');

        try {
            AccountPasswordPolicy::assertValid($password);
            Validator::make(
                ['email' => $email],
                ['email' => ['nullable', 'email', 'max:255']],
            )->validate();

            [$user, $existed] = DB::transaction(function () use (
                $credentials,
                $email,
                $emailProvided,
                $grantAdmin,
                $password,
                $username,
            ): array {
                $user = User::query()
                    ->where('username', $username)
                    ->lockForUpdate()
                    ->first();

                if ($user === null) {
                    $user = new User;
                    $user->username = $username;
                    // The User model casts password as hashed, so assign the plain value.
                    $user->password = $password;
                    $user->status = User::STATUS_NORMAL;
                    $user->is_admin = $grantAdmin;

                    if ($emailProvided) {
                        $user->email = $email;
                    }

                    $user->save();

                    return [$user, false];
                }

                if ($emailProvided
                    && $email === null
                    && $user->login_verify === User::LOGIN_VERIFY_EMAIL) {
                    throw ValidationException::withMessages([
                        'email' => 'Email verification requires a non-empty email address.',
                    ]);
                }

                $previousEmail = $user->email;

                if ($grantAdmin) {
                    $user->is_admin = true;
                }

                if ($emailProvided) {
                    $user->email = $email;
                }

                $user->save();

                return [$credentials->replacePassword($user, $password, [$previousEmail]), true];
            });
        } catch (ValidationException $exception) {
            $this->error($this->firstValidationError($exception));

            return self::FAILURE;
        }

        $displayUsername = json_encode($username, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG);
        $this->info(sprintf(
            '%s user %s%s.',
            $existed ? 'Updated' : 'Created',
            is_string($displayUsername) ? $displayUsername : '"[invalid username]"',
            $user->is_admin ? ' (admin)' : ''
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    private function resolvePassword(): array
    {
        $positional = $this->argument('password');
        $fromStdin = (bool) $this->option('password-stdin');

        if ($positional !== null && $fromStdin) {
            $this->error('Choose either the hidden password prompt, the deprecated positional argument, or --password-stdin.');

            return [null, self::INVALID];
        }

        if ($positional !== null) {
            $this->warn('Supplying a password as a positional argument is deprecated because shell history and process listings can expose it. Use the hidden prompt or --password-stdin.');

            return [(string) $positional, null];
        }

        if ($fromStdin) {
            $password = $this->readPasswordFromStdin();

            return [$password, $password === null ? self::FAILURE : null];
        }

        if (! $this->input->isInteractive()) {
            $this->error('A password is required. Run interactively for a hidden prompt or use --password-stdin.');

            return [null, self::FAILURE];
        }

        try {
            $password = $this->askHidden('Password');
            $confirmation = $this->askHidden('Confirm password');
        } catch (ConsoleRuntimeException) {
            $this->error('Secure password prompting is unavailable. Use --password-stdin instead.');

            return [null, self::FAILURE];
        }

        if (! is_string($password) || ! is_string($confirmation)) {
            $this->error('Password input was not received.');

            return [null, self::FAILURE];
        }

        if (! hash_equals($password, $confirmation)) {
            $this->error('The password confirmation does not match.');

            return [null, self::FAILURE];
        }

        return [$password, null];
    }

    private function askHidden(string $label): mixed
    {
        $question = (new Question($label))
            ->setHidden(true)
            ->setHiddenFallback(false)
            ->setTrimmable(false);

        $answer = $this->output->askQuestion($question);

        return is_string($answer) ? $this->withoutLineEnding($answer) : $answer;
    }

    private function readPasswordFromStdin(): ?string
    {
        $stream = $this->input instanceof StreamableInputInterface
            ? $this->input->getStream()
            : null;

        if (! is_resource($stream) && defined('STDIN')) {
            $stream = STDIN;
        }

        if (! is_resource($stream)) {
            $this->error('Standard input is unavailable.');

            return null;
        }

        if (function_exists('stream_isatty') && stream_isatty($stream)) {
            $this->error('Refusing to read an echoed password from a terminal. Omit --password-stdin to use the hidden prompt.');

            return null;
        }

        $maxBytes = AccountPasswordPolicy::MAX_LENGTH * 4;
        $line = fgets($stream, $maxBytes + 3);

        if ($line === false) {
            $this->error('No password was received from standard input.');

            return null;
        }

        $terminated = str_ends_with($line, "\n");
        $password = $this->withoutLineEnding($line);

        if (strlen($password) > $maxBytes || (! $terminated && ! feof($stream))) {
            $this->error('Password input exceeds the supported maximum length.');

            return null;
        }

        return $password;
    }

    private function withoutLineEnding(string $value): string
    {
        if (str_ends_with($value, "\r\n")) {
            return substr($value, 0, -2);
        }

        if (str_ends_with($value, "\n") || str_ends_with($value, "\r")) {
            return substr($value, 0, -1);
        }

        return $value;
    }

    private function firstValidationError(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            if (isset($messages[0])) {
                return (string) $messages[0];
            }
        }

        return $exception->getMessage();
    }
}
