<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\ApiKey;
use App\Models\Device;
use App\Models\Group;
use App\Models\Strategy;
use App\Models\StrategyAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A `null` option value in the v1 Strategy API means *Default*, exactly as it does in the editor:
 * the key leaves the stored map, so nothing is pushed for it and the client keeps its own value.
 *
 * The value this replaces was worse than useless. A `null` was stored verbatim, the model cast it
 * to `""` on the heartbeat, and a RustDesk client reads an empty value as "delete this key"
 * (`handle_config_options`, docs/modernization/10-client-config-keys.md §1) — so an administrator
 * asking for the default actively cleared the client's setting. An empty string keeps meaning an
 * empty string: it is a value the client is meant to receive.
 */
class ApiStrategyOptionDefaultTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= User::create([
            'username' => 'admin', 'password' => 'secret12345', 'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
    }

    /**
     * An API key owned by $user with the given scopes, ready for a Bearer header.
     *
     * @param  list<string>  $scopes
     */
    private function keyFor(User $user, array $scopes): string
    {
        [$plain, $prefix, $hash] = ApiKey::generateSecret();
        ApiKey::create([
            'user_id' => $user->id,
            'name' => 'Strategy writer',
            'token_hash' => $hash,
            'prefix' => $prefix,
            'scopes' => $scopes,
        ]);

        return $plain;
    }

    public function test_create_leaves_out_null_values_and_stores_an_empty_string_as_one(): void
    {
        $key = $this->keyFor($this->admin(), ['strategies.write']);

        $response = $this->withHeader('Authorization', 'Bearer '.$key)
            ->postJson('/api/v1/strategies', [
                'name' => 'Defaults',
                'options' => [
                    'enable-audio' => null,
                    'enable-clipboard' => '',
                    'direct-server' => 'N',
                ],
            ])
            ->assertCreated();

        // The response shows what is actually stored, so a caller sees the default took effect.
        $response->assertJsonPath('data.options', [
            'enable-clipboard' => '',
            'direct-server' => 'N',
        ]);

        $strategy = Strategy::query()->where('name', 'Defaults')->firstOrFail();
        $this->assertSame(['enable-clipboard' => '', 'direct-server' => 'N'], $strategy->options);

        // Nothing is pushed for the key the caller asked to reset: the client keeps its own value.
        $this->assertArrayNotHasKey('enable-audio', $strategy->pushableOptions());
        $this->assertSame('', $strategy->pushableOptions()['enable-clipboard']);
    }

    public function test_update_leaves_out_null_values_and_keeps_empty_strings_in_the_map(): void
    {
        $key = $this->keyFor($this->admin(), ['strategies.write']);
        $strategy = Strategy::create([
            'name' => 'Policy',
            'enabled' => true,
            'options' => ['enable-audio' => 'Y', 'enable-clipboard' => 'N'],
            'modified_at' => 1,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$key)
            ->putJson('/api/v1/strategies/'.$strategy->id, [
                'options' => [
                    'enable-audio' => null,
                    'enable-clipboard' => '',
                    'direct-server' => 'Y',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.options', [
                'enable-clipboard' => '',
                'direct-server' => 'Y',
            ]);

        $strategy->refresh();
        $this->assertSame(['enable-clipboard' => '', 'direct-server' => 'Y'], $strategy->options);
        $this->assertArrayNotHasKey('enable-audio', $strategy->options);
        // An empty string stays a stored, pushed value — unchanged from before this rule.
        $this->assertSame('', $strategy->pushableOptions()['enable-clipboard']);
    }

    public function test_a_map_of_only_null_values_stores_nothing(): void
    {
        $key = $this->keyFor($this->admin(), ['strategies.write']);
        $strategy = Strategy::create([
            'name' => 'Policy', 'enabled' => true, 'options' => ['enable-audio' => 'Y'], 'modified_at' => 1,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$key)
            ->putJson('/api/v1/strategies/'.$strategy->id, ['options' => ['enable-audio' => null]])
            ->assertOk();

        $this->assertSame([], $strategy->refresh()->options);
    }

    public function test_a_delegated_writer_cannot_clear_a_protected_key_with_null(): void
    {
        $inside = Group::create(['name' => 'Inside', 'type' => Group::TYPE_DEFAULT]);
        $delegate = User::create([
            'username' => 'delegate', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL, 'group_id' => $inside->id,
        ]);
        $delegate->adminRoles()->attach(AdminRole::create([
            'name' => 'Scoped', 'type' => AdminRole::TYPE_GROUP, 'scope' => [$inside->id],
            'perms' => ['strategies.view', 'strategies.edit', 'api_keys.edit'],
        ]));

        $device = Device::create([
            'rustdesk_id' => 'inside-peer', 'uuid' => 'uuid-inside', 'user_id' => $delegate->id, 'approved' => true,
        ]);
        $strategy = Strategy::create([
            'name' => 'Inside policy', 'enabled' => true, 'modified_at' => 1,
            'options' => ['proxy-url' => 'http://proxy.inside:8080', 'enable-audio' => 'Y'],
        ]);
        StrategyAssignment::create([
            'strategy_id' => $strategy->id,
            'target_type' => StrategyAssignment::TARGET_DEVICE,
            'target_id' => $device->id,
        ]);

        $key = $this->keyFor($delegate, ['strategies.write']);

        // `null` on a stored protected key is a removal, and removal is full-admin-only.
        $this->withHeader('Authorization', 'Bearer '.$key)
            ->putJson('/api/v1/strategies/'.$strategy->id, ['options' => ['proxy-url' => null]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.options.0', 'Only a full administrator can set or change: proxy-url.');

        $this->assertSame(
            ['proxy-url' => 'http://proxy.inside:8080', 'enable-audio' => 'Y'],
            $strategy->refresh()->options
        );

        // An ordinary key the delegate is allowed to reset still goes through.
        $this->withHeader('Authorization', 'Bearer '.$key)
            ->putJson('/api/v1/strategies/'.$strategy->id, ['options' => [
                'proxy-url' => 'http://proxy.inside:8080',
                'enable-audio' => null,
            ]])
            ->assertOk()
            ->assertJsonPath('data.options', ['proxy-url' => 'http://proxy.inside:8080']);
    }
}
