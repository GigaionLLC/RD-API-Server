<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\VerifyCode;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TotpLoginChallengeSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_first_step_returns_stock_flutter_shape_and_stores_only_a_bound_hash(): void
    {
        $user = $this->totpUser('shape-user');

        $response = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'secret12345',
            'id' => 'device-shape',
            'uuid' => 'uuid-shape',
            'autoLogin' => true,
            'type' => 'account',
            'deviceInfo' => ['os' => 'Linux'],
        ]);

        $response->assertOk()->assertJson([
            'type' => 'email_check',
            'tfa_type' => 'tfa_check',
            'user' => ['name' => 'shape-user'],
        ]);

        $body = $response->json();
        $this->assertIsArray($body);
        $this->assertArrayNotHasKey('access_token', $body);
        $this->assertIsString($body['secret']);
        $this->assertSame(64, strlen($body['secret']));

        $challenge = VerifyCode::firstOrFail();
        $this->assertSame($user->id, $challenge->user_id);
        $this->assertSame(VerifyCode::TYPE_TOTP, $challenge->type);
        $this->assertSame('device-shape', $challenge->rustdesk_id);
        $this->assertSame('uuid-shape', $challenge->uuid);
        $this->assertSame(hash('sha256', $body['secret']), $challenge->challenge_hash);
        $this->assertNotSame($body['secret'], $challenge->challenge_hash);
        $this->assertNull($challenge->code);
        $this->assertSame(0, $challenge->failed_attempts);
        $this->assertSame(5, $challenge->max_attempts);
        $this->assertSame(VerifyCode::STATUS_ACTIVE, $challenge->status);
        $this->assertNotNull($challenge->expires_at);
        $this->assertSame(0, AuthToken::count());
    }

    public function test_exact_stock_flutter_second_step_consumes_the_challenge_without_a_password(): void
    {
        $user = $this->totpUser('success-user');
        $secret = $this->issueChallenge($user, 'device-success', 'uuid-success');
        $code = app(TwoFactorService::class)->currentCode((string) $user->two_factor_secret);

        $payload = $this->stockSecondStep(
            $user,
            'device-success',
            'uuid-success',
            $secret,
            $code,
        );
        $this->assertArrayNotHasKey('password', $payload);

        $this->postJson('/api/login', $payload)
            ->assertOk()
            ->assertJsonPath('type', 'access_token')
            ->assertJsonStructure(['access_token', 'user']);

        $challenge = $this->challengeFor($secret);
        $this->assertSame(VerifyCode::STATUS_INACTIVE, $challenge->status);
        $this->assertNotNull($challenge->consumed_at);
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

    public function test_challenge_requires_the_same_user_device_uuid_secret_and_code_fields(): void
    {
        $user = $this->totpUser('bound-user');
        $other = $this->totpUser('other-user');
        $secret = $this->issueChallenge($user, 'device-bound', 'uuid-bound');
        $code = app(TwoFactorService::class)->currentCode((string) $user->two_factor_secret);
        $payload = $this->stockSecondStep($user, 'device-bound', 'uuid-bound', $secret, $code);
        $differentCode = $code === '000000' ? '000001' : '000000';

        foreach ([
            array_diff_key($payload, ['secret' => true]),
            [...$payload, 'secret' => str_repeat('A', 64)],
            [...$payload, 'id' => 'different-device'],
            [...$payload, 'uuid' => 'different-uuid'],
            [...$payload, 'username' => $other->username],
            [...$payload, 'verificationCode' => $differentCode],
        ] as $invalidPayload) {
            $this->postJson('/api/login', $invalidPayload)
                ->assertOk()
                ->assertJsonStructure(['error']);
        }

        $challenge = $this->challengeFor($secret);
        $this->assertSame(VerifyCode::STATUS_ACTIVE, $challenge->status);
        $this->assertSame(0, $challenge->failed_attempts);
        $this->assertSame(0, AuthToken::count());

        $this->postJson('/api/login', $payload)
            ->assertOk()
            ->assertJsonStructure(['access_token']);
    }

    public function test_a_totp_code_alone_cannot_bypass_the_password_first_factor(): void
    {
        $user = $this->totpUser('no-first-factor-user');
        $code = app(TwoFactorService::class)->currentCode((string) $user->two_factor_secret);

        $this->postJson('/api/login', $this->stockSecondStep(
            $user,
            'device-unproven',
            'uuid-unproven',
            str_repeat('A', 64),
            $code,
        ))
            ->assertOk()
            ->assertJsonStructure(['error']);

        $this->assertSame(0, VerifyCode::count());
        $this->assertSame(0, AuthToken::count());
    }

    public function test_expired_superseded_and_replayed_challenges_cannot_issue_tokens(): void
    {
        $user = $this->totpUser('lifecycle-user');
        $service = app(TwoFactorService::class);

        $expired = $this->issueChallenge($user, 'device-expired', 'uuid-expired');
        $this->challengeFor($expired)->forceFill(['expires_at' => now()->subSecond()])->save();
        $code = $service->currentCode((string) $user->two_factor_secret);

        $this->postJson('/api/login', $this->stockSecondStep(
            $user,
            'device-expired',
            'uuid-expired',
            $expired,
            $code,
        ))->assertOk()->assertJsonStructure(['error']);
        $this->assertSame(
            VerifyCode::STATUS_INACTIVE,
            $this->challengeFor($expired)->status,
        );

        $first = $this->issueChallenge($user, 'device-current', 'uuid-current');
        $second = $this->issueChallenge($user, 'device-current', 'uuid-current');
        $this->assertSame(VerifyCode::STATUS_INACTIVE, $this->challengeFor($first)->status);

        $this->postJson('/api/login', $this->stockSecondStep(
            $user,
            'device-current',
            'uuid-current',
            $first,
            $service->currentCode((string) $user->two_factor_secret),
        ))->assertOk()->assertJsonStructure(['error']);

        $validPayload = $this->stockSecondStep(
            $user,
            'device-current',
            'uuid-current',
            $second,
            $service->currentCode((string) $user->two_factor_secret),
        );
        $this->postJson('/api/login', $validPayload)
            ->assertOk()
            ->assertJsonStructure(['access_token']);
        $this->postJson('/api/login', $validPayload)
            ->assertOk()
            ->assertJsonStructure(['error']);

        $this->assertSame(1, AuthToken::count());
    }

    public function test_five_wrong_codes_exhaust_the_challenge_even_when_source_ips_rotate(): void
    {
        $user = $this->totpUser('attempt-user');
        $secret = $this->issueChallenge($user, 'device-attempt', 'uuid-attempt');
        $wrongCode = $this->invalidTotpCode($user);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$attempt])
                ->postJson('/api/login', $this->stockSecondStep(
                    $user,
                    'device-attempt',
                    'uuid-attempt',
                    $secret,
                    $wrongCode,
                ))
                ->assertOk()
                ->assertJsonStructure(['error']);
        }

        $challenge = $this->challengeFor($secret);
        $this->assertSame(5, $challenge->failed_attempts);
        $this->assertSame(VerifyCode::STATUS_INACTIVE, $challenge->status);

        $validCode = app(TwoFactorService::class)->currentCode((string) $user->two_factor_secret);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->postJson('/api/login', $this->stockSecondStep(
                $user,
                'device-attempt',
                'uuid-attempt',
                $secret,
                $validCode,
            ))
            ->assertOk()
            ->assertJsonStructure(['error']);
        $this->assertSame(0, AuthToken::count());
    }

    public function test_password_and_totp_in_one_request_remains_compatible(): void
    {
        $user = $this->totpUser('direct-user');
        $service = app(TwoFactorService::class);
        $validCode = $service->currentCode((string) $user->two_factor_secret);
        $wrongCode = $this->invalidTotpCode($user);
        $base = [
            'username' => $user->username,
            'id' => 'device-direct',
            'uuid' => 'uuid-direct',
            'type' => 'account',
        ];

        $this->postJson('/api/login', [
            ...$base,
            'password' => 'wrong-password',
            'tfaCode' => $validCode,
        ])->assertOk()->assertJsonStructure(['error']);
        $this->postJson('/api/login', [
            ...$base,
            'password' => 'secret12345',
            'tfaCode' => $wrongCode,
        ])->assertOk()->assertJsonStructure(['error']);
        $this->assertSame(0, AuthToken::count());

        $this->postJson('/api/login', [
            ...$base,
            'password' => 'secret12345',
            'tfaCode' => $service->currentCode((string) $user->two_factor_secret),
        ])->assertOk()->assertJsonStructure(['access_token']);

        $this->assertSame(1, AuthToken::count());
        $this->assertSame(0, VerifyCode::where('type', VerifyCode::TYPE_TOTP)->count());
    }

    private function totpUser(string $username): User
    {
        return User::create([
            'username' => $username,
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
            'two_factor_enabled' => true,
            'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function issueChallenge(User $user, string $rustdeskId, string $uuid): string
    {
        $response = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'secret12345',
            'id' => $rustdeskId,
            'uuid' => $uuid,
            'autoLogin' => true,
            'type' => 'account',
        ])->assertOk()->assertJson([
            'type' => 'email_check',
            'tfa_type' => 'tfa_check',
            'user' => ['name' => $user->username],
        ]);

        $secret = $response->json('secret');
        $this->assertIsString($secret);

        return $secret;
    }

    /**
     * @return array<string, mixed>
     */
    private function stockSecondStep(
        User $user,
        string $rustdeskId,
        string $uuid,
        string $secret,
        string $code,
    ): array {
        return [
            'username' => $user->username,
            'id' => $rustdeskId,
            'uuid' => $uuid,
            'autoLogin' => true,
            'type' => 'email_code',
            'verificationCode' => $code,
            'tfaCode' => $code,
            'secret' => $secret,
            'deviceInfo' => ['os' => 'Linux', 'type' => 'desktop', 'name' => 'Test device'],
        ];
    }

    private function challengeFor(string $secret): VerifyCode
    {
        return VerifyCode::where('challenge_hash', hash('sha256', $secret))->firstOrFail();
    }

    private function invalidTotpCode(User $user): string
    {
        $service = app(TwoFactorService::class);

        for ($candidate = 0; $candidate <= 999999; $candidate++) {
            $code = str_pad((string) $candidate, 6, '0', STR_PAD_LEFT);
            if (! $service->verifyTotp($user, $code)) {
                return $code;
            }
        }

        $this->fail('Could not find an invalid TOTP code.');
    }
}
