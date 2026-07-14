<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyOptionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_rejects_unsafe_option_keys_and_multiline_values(): void
    {
        $admin = User::create([
            'username' => 'admin',
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
        [$plain, $prefix, $hash] = ApiKey::generateSecret();
        ApiKey::create([
            'user_id' => $admin->id,
            'name' => 'Strategy writer',
            'token_hash' => $hash,
            'prefix' => $prefix,
            'scopes' => ['strategies.write'],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/strategies', [
                'name' => 'Unsafe key',
                'options' => ['unsafe;id' => 'N'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options');

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/strategies', [
                'name' => 'Multiline value',
                'options' => ['safe-key' => "first\r\nsecond"],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options.safe-key');
    }
}
