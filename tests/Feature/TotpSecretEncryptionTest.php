<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\TotpSecretFormat;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TotpSecretEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_secret_validation_bounds_the_raw_value(): void
    {
        $this->assertTrue(TotpSecretFormat::isValid(str_repeat('A', 16)));
        $this->assertFalse(TotpSecretFormat::isValid(
            str_repeat('A', 16).str_repeat(' ', 240),
        ));
    }

    public function test_totp_secret_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $user = User::create([
            'username' => 'encrypted-totp-user',
            'password' => 'legacy-password',
            'status' => User::STATUS_NORMAL,
            'two_factor_secret' => $secret,
        ]);

        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertIsString($raw);
        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertSame($secret, Crypt::decryptString($raw));
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
        $this->assertArrayNotHasKey('two_factor_secret', $user->fresh()->toArray());

        $withoutSecret = User::create([
            'username' => 'null-totp-user',
            'password' => 'legacy-password',
            'status' => User::STATUS_NORMAL,
            'two_factor_secret' => null,
        ]);
        $this->assertNull($withoutSecret->fresh()->two_factor_secret);
        $this->assertNull(DB::table('users')
            ->where('id', $withoutSecret->id)
            ->value('two_factor_secret'));
    }

    public function test_totp_secret_can_be_decrypted_with_a_configured_previous_key(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $previousBytes = str_repeat('p', 32);
        $previousKey = 'base64:'.base64_encode($previousBytes);
        $legacyEncrypter = new Encrypter($previousBytes, (string) config('app.cipher'));
        $ciphertext = $legacyEncrypter->encryptString($secret);
        $userId = DB::table('users')->insertGetId([
            'username' => 'previous-key-totp-user',
            'password' => 'legacy-password',
            'status' => User::STATUS_NORMAL,
            'two_factor_secret' => $ciphertext,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $originalKey = config('app.key');
        $originalPreviousKeys = config('app.previous_keys', []);

        try {
            config([
                'app.key' => $originalKey,
                'app.previous_keys' => [$previousKey],
            ]);
            $this->rebuildEncrypter();

            $this->assertSame($secret, User::findOrFail($userId)->two_factor_secret);
        } finally {
            config([
                'app.key' => $originalKey,
                'app.previous_keys' => $originalPreviousKeys,
            ]);
            $this->rebuildEncrypter();
        }
    }

    public function test_corrupt_totp_ciphertext_fails_closed(): void
    {
        $userId = DB::table('users')->insertGetId([
            'username' => 'corrupt-totp-user',
            'password' => 'legacy-password',
            'status' => User::STATUS_NORMAL,
            'two_factor_secret' => 'not-valid-ciphertext!',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(DecryptException::class);

        User::findOrFail($userId)->two_factor_secret;
    }

    private function rebuildEncrypter(): void
    {
        User::encryptUsing(null);
        Crypt::clearResolvedInstance('encrypter');
        $this->app->forgetInstance('encrypter');
    }
}
