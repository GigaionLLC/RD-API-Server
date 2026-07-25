<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\BootstrapAdminCredentials;
use App\Support\InitialAdminPassword;
use App\Support\ProtectedAdministrator;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Default administrator. Created only if absent, so a password later changed in the
        // UI is never overwritten by re-seeding. Use `php artisan rustdesk:user ... --admin`
        // to add more admins or reset a password.
        //
        // The existence check and the insert run in one locked transaction: two replicas booting
        // against the same database would otherwise both pass the check and race to create the
        // account, and with a generated password the loser's credential would be the one printed.
        $adminUsername = (string) config('bootstrap.admin.username', 'admin');
        DB::transaction(function () use ($adminUsername): void {
            $existing = User::where('username', $adminUsername)->lockForUpdate()->first();
            if ($existing !== null) {
                // Never rotate. A boot that silently changed the administrator's password would
                // lock out whoever is already using it.
                return;
            }

            $configuredPassword = config('bootstrap.admin.password');
            $generated = BootstrapAdminCredentials::isMissing(
                is_string($configuredPassword) ? $configuredPassword : null
            ) && app()->environment('production');

            $adminPassword = BootstrapAdminCredentials::resolvePassword(
                is_string($configuredPassword) ? $configuredPassword : null,
                $adminUsername,
                app()->environment('production'),
            );

            $admin = User::create([
                'username' => $adminUsername,
                'password' => $adminPassword,
                'is_admin' => true,
                'status' => User::STATUS_NORMAL,
                'display_name' => 'Administrator',
            ]);

            // The first administrator is the break-glass account: it is the only one that exists
            // before any identity provider is configured.
            ProtectedAdministrator::designate($admin);

            if ($generated) {
                InitialAdminPassword::publish($adminUsername, $adminPassword);
            }
        });

        $this->call([
            MailTemplateSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
