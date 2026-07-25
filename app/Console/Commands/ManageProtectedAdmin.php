<?php

namespace App\Console\Commands;

use App\Models\LdapIdentity;
use App\Models\User;
use App\Models\UserThird;
use App\Support\ProtectedAdministrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Move or remove the break-glass designation.
 *
 * Deliberately CLI-only. The whole point of the protected account is that the console cannot take
 * it away, so the console must not be able to reassign it either: an attacker with an admin session
 * would otherwise transfer the designation to themselves and lock the real operator out.
 */
class ManageProtectedAdmin extends Command
{
    protected $signature = 'rustdesk:admin:protect
        {username? : The account to designate}
        {--show : Print which account is currently designated}
        {--clear : Remove the designation entirely}
        {--i-understand : Required with --clear}';

    protected $description = 'Show, transfer, or clear the break-glass administrator designation';

    public function handle(): int
    {
        if ($this->option('show')) {
            return $this->show();
        }

        if ($this->option('clear')) {
            return $this->clear();
        }

        return $this->designate();
    }

    private function show(): int
    {
        $current = ProtectedAdministrator::current();

        if ($current === null) {
            $this->warn('No break-glass administrator is designated.');
            $this->line('  Designate one with: php artisan rustdesk:admin:protect <username>');

            return self::SUCCESS;
        }

        $this->info(sprintf('Break-glass administrator: %s (id %d)', $current->username, $current->id));

        return self::SUCCESS;
    }

    private function clear(): int
    {
        if (! $this->option('i-understand')) {
            $this->error('Refusing to clear the designation without --i-understand.');
            $this->line('  Nothing would then stop an identity provider, or another administrator,');
            $this->line('  from removing the last account able to reach this console.');

            return self::FAILURE;
        }

        $current = ProtectedAdministrator::current();
        if ($current === null) {
            $this->warn('No break-glass administrator is designated; nothing to clear.');

            return self::SUCCESS;
        }

        ProtectedAdministrator::designate(null);
        $this->warn(sprintf('Cleared the break-glass designation from "%s".', $current->username));
        Log::warning('Break-glass administrator designation cleared', [
            'user_id' => $current->id,
            'username' => $current->username,
        ]);

        return self::SUCCESS;
    }

    private function designate(): int
    {
        $username = (string) $this->argument('username');
        if ($username === '') {
            $this->error('Provide a username, or use --show or --clear.');

            return self::FAILURE;
        }

        $user = User::where('username', $username)->first();
        if ($user === null) {
            $this->error(sprintf('No account named "%s" exists.', $username));

            return self::FAILURE;
        }

        // Each refusal describes an account that could not actually be used to recover access,
        // which would make the designation worse than useless: it would look like a safety net.
        $problems = [];

        if (! $user->is_admin) {
            $problems[] = 'it is not a full administrator';
        }

        if ((int) $user->status !== User::STATUS_NORMAL) {
            $problems[] = 'it is not active';
        }

        if ($user->force_sso) {
            $problems[] = 'it is restricted to SSO sign-in';
        }

        if (LdapIdentity::where('user_id', $user->id)->exists() || UserThird::where('user_id', $user->id)->exists()) {
            $problems[] = 'it has a linked LDAP or SSO identity, so it cannot hold a local password';
        }

        if ($problems !== []) {
            $this->error(sprintf('"%s" cannot be the break-glass administrator:', $username));
            foreach ($problems as $problem) {
                $this->line('  - '.$problem);
            }
            $this->line('  Fix these first, for example with: php artisan rustdesk:admin:reset '.$username);

            return self::FAILURE;
        }

        $previous = ProtectedAdministrator::current();
        ProtectedAdministrator::designate($user);

        $this->info(sprintf('"%s" is now the break-glass administrator.', $username));
        if ($previous !== null && $previous->id !== $user->id) {
            $this->line(sprintf('  The designation moved from "%s".', $previous->username));
        }

        Log::warning('Break-glass administrator designated', [
            'user_id' => $user->id,
            'username' => $user->username,
            'previous_user_id' => $previous?->id,
        ]);

        return self::SUCCESS;
    }
}
