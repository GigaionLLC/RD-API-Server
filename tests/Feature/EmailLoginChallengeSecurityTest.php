<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\VerifyCode;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailLoginChallengeSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_first_step_returns_the_stock_client_shape_and_stores_only_hashes(): void
    {
        Mail::fake();
        $user = $this->emailUser('shape-user');

        $response = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'secret12345',
            'id' => 'device-shape',
            'uuid' => 'uuid-shape',
            'type' => 'account',
        ]);

        $response->assertOk()->assertJson([
            'type' => 'email_check',
            'tfa_type' => 'email_check',
            'user' => ['name' => 'shape-user'],
        ]);
        $secret = $response->json('secret');
        $this->assertIsString($secret);
        $this->assertSame(64, strlen($secret));

        $record = VerifyCode::firstOrFail();
        $this->assertSame(hash('sha256', $secret), $record->challenge_hash);
        $this->assertNotSame($secret, $record->challenge_hash);
        $this->assertDoesNotMatchRegularExpression('/\A\d{6}\z/', (string) $record->code);
        $this->assertSame('device-shape', $record->rustdesk_id);
        $this->assertSame('uuid-shape', $record->uuid);
        $this->assertSame(0, $record->failed_attempts);
        $this->assertSame(5, $record->max_attempts);
        $this->assertSame(VerifyCode::STATUS_ACTIVE, $record->status);
        $this->assertNotNull($record->expires_at);
        $this->assertArrayNotHasKey('code', $record->toArray());
        $this->assertArrayNotHasKey('challenge_hash', $record->toArray());
    }

    public function test_stock_second_step_consumes_the_challenge_and_cannot_be_replayed(): void
    {
        $user = $this->emailUser('success-user');
        $issued = app(TwoFactorService::class)->issueEmailCode(
            $user,
            'uuid-success',
            'device-success'
        );
        $record = VerifyCode::firstOrFail();
        $this->assertTrue(Hash::check($issued['code'], (string) $record->code));

        $payload = [
            'username' => $user->username,
            'id' => 'device-success',
            'uuid' => 'uuid-success',
            'type' => 'email_code',
            'verificationCode' => $issued['code'],
            'secret' => $issued['secret'],
        ];

        $this->postJson('/api/login', $payload)
            ->assertOk()
            ->assertJsonPath('type', 'access_token')
            ->assertJsonStructure(['access_token', 'user']);

        $record->refresh();
        $this->assertSame(VerifyCode::STATUS_INACTIVE, $record->status);
        $this->assertNotNull($record->consumed_at);
        $this->assertSame(0, $record->failed_attempts);
        $this->assertDatabaseHas('auth_tokens', [
            'user_id' => $user->id,
            'rustdesk_id' => 'device-success',
            'uuid' => 'uuid-success',
        ]);

        $this->postJson('/api/login', $payload)
            ->assertOk()
            ->assertJsonStructure(['error']);
        $this->assertSame(1, AuthToken::count());
    }

    public function test_challenge_requires_the_same_user_device_uuid_and_echoed_secret(): void
    {
        $user = $this->emailUser('bound-user');
        $other = $this->emailUser('other-user');
        $issued = app(TwoFactorService::class)->issueEmailCode($user, 'uuid-bound', 'device-bound');
        $base = [
            'username' => $user->username,
            'id' => 'device-bound',
            'uuid' => 'uuid-bound',
            'type' => 'email_code',
            'verificationCode' => $issued['code'],
            'secret' => $issued['secret'],
        ];

        foreach ([
            array_diff_key($base, ['secret' => true]),
            [...$base, 'username' => $other->username],
            [...$base, 'id' => 'different-device'],
            [...$base, 'uuid' => 'different-uuid'],
            [...$base, 'secret' => str_repeat('A', 64)],
        ] as $payload) {
            $this->postJson('/api/login', $payload)
                ->assertOk()
                ->assertJsonStructure(['error']);
        }

        $record = VerifyCode::firstOrFail();
        $this->assertSame(VerifyCode::STATUS_ACTIVE, $record->status);
        $this->assertSame(0, $record->failed_attempts);
        $this->assertSame(0, AuthToken::count());

        $this->postJson('/api/login', $base)
            ->assertOk()
            ->assertJsonStructure(['access_token']);
    }

    public function test_five_wrong_codes_exhaust_the_challenge_even_when_source_ips_rotate(): void
    {
        $user = $this->emailUser('attempt-user');
        $issued = app(TwoFactorService::class)->issueEmailCode(
            $user,
            'uuid-attempt',
            'device-attempt'
        );
        $payload = [
            'username' => $user->username,
            'id' => 'device-attempt',
            'uuid' => 'uuid-attempt',
            'type' => 'email_code',
            'verificationCode' => $issued['code'] === '000000' ? '000001' : '000000',
            'secret' => $issued['secret'],
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$attempt])
                ->postJson('/api/login', $payload)
                ->assertOk()
                ->assertJsonStructure(['error']);
        }

        $record = VerifyCode::firstOrFail();
        $this->assertSame(5, $record->failed_attempts);
        $this->assertSame(VerifyCode::STATUS_INACTIVE, $record->status);

        $payload['verificationCode'] = $issued['code'];
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->postJson('/api/login', $payload)
            ->assertOk()
            ->assertJsonStructure(['error']);
        $this->assertSame(0, AuthToken::count());
    }

    public function test_expired_and_superseded_challenges_cannot_issue_tokens(): void
    {
        $user = $this->emailUser('lifecycle-user');
        $expired = app(TwoFactorService::class)->issueEmailCode(
            $user,
            'uuid-expired',
            'device-expired'
        );
        $expiredRecord = VerifyCode::firstOrFail();
        $expiredRecord->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->postJson('/api/login', [
            'username' => $user->username,
            'id' => 'device-expired',
            'uuid' => 'uuid-expired',
            'type' => 'email_code',
            'verificationCode' => $expired['code'],
            'secret' => $expired['secret'],
        ])->assertOk()->assertJsonStructure(['error']);
        $this->assertSame(VerifyCode::STATUS_INACTIVE, $expiredRecord->refresh()->status);

        $first = app(TwoFactorService::class)->issueEmailCode(
            $user,
            'uuid-current',
            'device-current'
        );
        $firstRecord = VerifyCode::where('uuid', 'uuid-current')->firstOrFail();
        $second = app(TwoFactorService::class)->issueEmailCode(
            $user,
            'uuid-current',
            'device-current'
        );
        $this->assertSame(VerifyCode::STATUS_INACTIVE, $firstRecord->refresh()->status);

        $this->postJson('/api/login', [
            'username' => $user->username,
            'id' => 'device-current',
            'uuid' => 'uuid-current',
            'type' => 'email_code',
            'verificationCode' => $first['code'],
            'secret' => $first['secret'],
        ])->assertOk()->assertJsonStructure(['error']);

        $this->postJson('/api/login', [
            'username' => $user->username,
            'id' => 'device-current',
            'uuid' => 'uuid-current',
            'type' => 'email_code',
            'verificationCode' => $second['code'],
            'secret' => $second['secret'],
        ])->assertOk()->assertJsonStructure(['access_token']);

        $this->assertSame(1, AuthToken::count());
    }

    public function test_first_step_refuses_to_create_an_unbound_challenge(): void
    {
        Mail::fake();
        $user = $this->emailUser('unbound-user');

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'secret12345',
            'id' => 'device-only',
            'type' => 'account',
        ])->assertOk()->assertJsonStructure(['error']);

        $this->assertSame(0, VerifyCode::count());
        $this->assertSame(0, AuthToken::count());
    }

    public function test_migration_retires_legacy_plaintext_codes(): void
    {
        $migration = require database_path(
            'migrations/2026_07_14_100003_harden_email_login_challenges.php'
        );
        $migration->down();

        DB::table('verify_codes')->insert([
            'user_id' => 1,
            'type' => VerifyCode::TYPE_EMAIL,
            'uuid' => 'legacy-uuid',
            'code' => '123456',
            'rustdesk_id' => 'legacy-device',
            'status' => VerifyCode::STATUS_ACTIVE,
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $legacy = DB::table('verify_codes')->where('uuid', 'legacy-uuid')->first();
        $this->assertNotNull($legacy);
        $this->assertNull($legacy->code);
        $this->assertNull($legacy->challenge_hash);
        $this->assertSame(VerifyCode::STATUS_INACTIVE, $legacy->status);

        DB::table('verify_codes')->where('uuid', 'legacy-uuid')->update([
            'challenge_hash' => str_repeat('a', 64),
            'code' => Hash::make('654321'),
            'status' => VerifyCode::STATUS_ACTIVE,
        ]);
        $migration->down();

        $rolledBack = DB::table('verify_codes')->where('uuid', 'legacy-uuid')->first();
        $this->assertNotNull($rolledBack);
        $this->assertNull($rolledBack->code);
        $this->assertSame(VerifyCode::STATUS_INACTIVE, $rolledBack->status);

        // Restore the expected schema for RefreshDatabase teardown and any following test.
        $migration->up();
    }

    private function emailUser(string $username): User
    {
        return User::create([
            'username' => $username,
            'email' => $username.'@example.test',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_EMAIL,
        ]);
    }
}
