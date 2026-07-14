<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountCredentialService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create (or update the password of) a user from the CLI.
 *   php artisan rustdesk:user admin secret --admin
 *   php artisan rustdesk:user alice secret --email=alice@example.com
 */
class CreateUser extends Command
{
    protected $signature = 'rustdesk:user
        {username : The username}
        {password : The password}
        {--email= : Optional email address}
        {--admin : Grant administrator privileges}';

    protected $description = 'Create a user (or reset its password) for the admin console / client API';

    public function handle(AccountCredentialService $credentials): int
    {
        $username = (string) $this->argument('username');
        $password = (string) $this->argument('password');

        $user = User::firstOrNew(['username' => $username]);
        $existed = $user->exists;
        $previousEmail = $user->email;

        if (! $existed) {
            // The User model casts 'password' as 'hashed', so assign the plain value.
            $user->password = $password;
        }

        $user->is_admin = (bool) $this->option('admin');
        $user->status = User::STATUS_NORMAL;

        if ($email = $this->option('email')) {
            $user->email = $email;
        }

        if ($existed) {
            try {
                $user = DB::transaction(function () use ($user, $credentials, $password, $previousEmail): User {
                    $user->save();

                    return $credentials->replacePassword($user, $password, [$previousEmail]);
                });
            } catch (ValidationException $exception) {
                $this->error((string) ($exception->errors()['password'][0] ?? $exception->getMessage()));

                return self::FAILURE;
            }
        } else {
            $user->save();
        }

        $this->info(sprintf(
            '%s user "%s"%s.',
            $existed ? 'Updated' : 'Created',
            $username,
            $user->is_admin ? ' (admin)' : ''
        ));

        return self::SUCCESS;
    }
}
