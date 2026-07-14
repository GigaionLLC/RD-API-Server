<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LocalPasswordHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordHashingSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_hashes_use_argon2id_and_do_not_truncate_after_72_bytes(): void
    {
        $this->assertSame('argon2id', config('hashing.driver'));
        $this->assertSame(72, config('hashing.bcrypt.limit'));

        $prefix = str_repeat('a', 72);
        $password = $prefix.'x';
        $user = $this->user('argon-user', $password);

        $this->assertSame('argon2id', password_get_info((string) $user->password)['algoName']);
        $this->assertTrue(Hash::check($password, (string) $user->password));
        $this->assertFalse(Hash::check($prefix.'y', (string) $user->password));
    }

    public function test_admin_login_upgrades_a_legacy_bcrypt_hash(): void
    {
        $user = $this->legacyBcryptUser('legacy-web-admin', 'legacy-web-password', true);

        $this->post(route('admin.login'), [
            'username' => $user->username,
            'password' => 'legacy-web-password',
        ])->assertRedirect(route('admin.dashboard'));

        $user->refresh();
        $this->assertSame('argon2id', password_get_info((string) $user->password)['algoName']);
        $this->assertTrue(Hash::check('legacy-web-password', (string) $user->password));
        $this->assertSame(1, $user->credential_version);
    }

    public function test_client_login_upgrades_a_legacy_bcrypt_hash(): void
    {
        $user = $this->legacyBcryptUser('legacy-client-user', 'legacy-client-password');

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'legacy-client-password',
        ])->assertOk()->assertJsonStructure(['access_token']);

        $user->refresh();
        $this->assertSame('argon2id', password_get_info((string) $user->password)['algoName']);
        $this->assertTrue(Hash::check('legacy-client-password', (string) $user->password));
        $this->assertSame(1, $user->credential_version);
    }

    public function test_wrong_legacy_password_does_not_change_the_hash(): void
    {
        $user = $this->legacyBcryptUser('legacy-wrong-user', 'legacy-correct-password');
        $storedHash = (string) $user->password;

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'legacy-wrong-password',
        ])->assertOk()->assertExactJson(['error' => 'Invalid username or password']);

        $this->assertSame($storedHash, (string) $user->refresh()->password);
        $this->assertSame('bcrypt', password_get_info((string) $user->password)['algoName']);
    }

    public function test_hash_upgrade_cannot_overwrite_a_concurrent_password_replacement(): void
    {
        $user = $this->legacyBcryptUser('legacy-race-user', 'legacy-race-password');
        $staleUser = $user->fresh();
        $replacementHash = Hash::make('newer-replacement-password');

        DB::table('users')->where('id', $user->id)->update([
            'password' => $replacementHash,
            'credential_version' => 2,
        ]);

        $this->assertFalse(
            app(LocalPasswordHashService::class)->upgradeIfNeeded($staleUser, 'legacy-race-password'),
        );

        $user->refresh();
        $this->assertSame($replacementHash, (string) $user->password);
        $this->assertTrue(Hash::check('newer-replacement-password', (string) $user->password));
        $this->assertSame(2, $user->credential_version);
    }

    private function legacyBcryptUser(string $username, string $password, bool $admin = false): User
    {
        $user = $this->user($username, 'temporary-password', $admin);
        $bcrypt = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertIsString($bcrypt);

        // The model's hashed cast correctly rejects foreign algorithms when assigning a new
        // value. Raw SQL emulates a row created before the Argon2id migration.
        DB::table('users')->where('id', $user->id)->update(['password' => $bcrypt]);

        return $user->fresh();
    }

    private function user(string $username, string $password, bool $admin = false): User
    {
        return User::create([
            'username' => $username,
            'password' => $password,
            'is_admin' => $admin,
            'status' => User::STATUS_NORMAL,
        ]);
    }
}
