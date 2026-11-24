<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\ApiKey;
use App\Models\Device;
use App\Models\Group;
use App\Models\Strategy;
use App\Models\StrategyAssignment;
use App\Models\User;
use App\Services\ClientConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client-version behaviour of the strategy option catalog:
 *   - keys a 1.5.3+ client strips from a server push stay settable but are flagged,
 *   - `allow-linux-headless` (removed upstream in 1.5.0) stays loadable, is preserved on save,
 *     and is no longer pushed,
 *   - the free-form custom-option row warns instead of silently accepting a stripped key.
 *
 * Upstream references: libs/base/src/config/keys.rs and BLOCKED_CONFIG_OPTIONS in
 * src/hbbs_http/sync.rs at rustdesk a0c0963c.
 */
class StrategyOptionLifecycleTest extends TestCase
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
     * @return array<string, array<string, mixed>>
     */
    private function catalog(): array
    {
        $options = [];
        foreach (config('strategy_options.tabs') as $tab) {
            foreach ($tab['sections'] as $section) {
                foreach ($section['options'] as $opt) {
                    $options[$opt['key']] = $opt + ['tab' => $tab['key'], 'section' => $section['label']];
                }
            }
        }

        return $options;
    }

    public function test_catalog_flags_exactly_the_client_ignored_keys_it_offers(): void
    {
        $catalog = $this->catalog();

        foreach (['allow-insecure-tls-fallback', 'allow-websocket', 'enable-trusted-devices'] as $key) {
            $this->assertArrayHasKey($key, $catalog, "Catalog is missing {$key}");
            $this->assertSame('1.5.3', $catalog[$key]['strategy_ignored_since'] ?? null, "{$key} must be flagged as ignored by 1.5.3+");
        }

        foreach ($catalog as $key => $opt) {
            if (isset($opt['strategy_ignored_since'])) {
                $this->assertArrayHasKey(
                    $key,
                    ClientConfigService::STRATEGY_IGNORED_SINCE,
                    "{$key} is flagged in the catalog but not on the client deny-list",
                );
            }
        }

        // The client strips these keys and the catalog offers none of them as a toggle.
        foreach (['2fa', 'bot', 'preset-address-book-password', 'trusted-device-expiration-days', 'stop-service'] as $blocked) {
            $this->assertArrayNotHasKey($blocked, $catalog);
            $this->assertArrayHasKey($blocked, ClientConfigService::STRATEGY_IGNORED_SINCE);
        }
    }

    public function test_deny_list_mirrors_the_upstream_block_list(): void
    {
        $this->assertSame([
            // Server endpoints and trust.
            'custom-rendezvous-server',
            'rendezvous-servers',
            'api-server',
            'relay-server',
            'key',
            'ice-servers',
            // Transport security.
            'allow-insecure-tls-fallback',
            'allow-websocket',
            'websocket-port',
            // Authentication and credentials.
            '2fa',
            'bot',
            'preset-address-book-password',
            'enable-trusted-devices',
            'trusted-device-expiration-days',
            // Deployment and address-book metadata.
            'preset-address-book-name',
            'preset-address-book-tag',
            'preset-address-book-alias',
            'preset-address-book-note',
            'preset-device-name',
            'preset-device-username',
            'preset-note',
            // Recording location and service state.
            'windows-service-video-save-directory',
            'stop-service',
            'reg_recovery',
        ], array_keys(ClientConfigService::STRATEGY_IGNORED_SINCE));
    }

    public function test_drm_capture_is_a_linux_toggle_in_the_performance_section(): void
    {
        $catalog = $this->catalog();

        $this->assertArrayHasKey('allow-drm-capture', $catalog);
        $this->assertSame('toggle', $catalog['allow-drm-capture']['type']);
        $this->assertSame('general', $catalog['allow-drm-capture']['tab']);
        $this->assertSame('Performance & display', $catalog['allow-drm-capture']['section']);
        $this->assertStringContainsString('Linux login screen', $catalog['allow-drm-capture']['note']);
        $this->assertStringContainsString('newer than 1.5.3', $catalog['allow-drm-capture']['note']);
    }

    public function test_editor_shows_the_client_ignored_warning_and_the_option_notes(): void
    {
        $strategy = Strategy::create(['name' => 'P', 'enabled' => true, 'options' => [], 'modified_at' => 1]);

        $this->actingAs($this->admin())->get(route('admin.strategies.edit', $strategy))
            ->assertOk()
            ->assertSee('opt[allow-drm-capture]')
            ->assertSee('opt[id-whitelist]')
            ->assertSee('opt[allow-kcp-congestion-control]')
            ->assertSee('Linux login screen and Wayland sessions through DRM/KMS')
            ->assertSee('ignores this when pushed by a server');
    }

    public function test_free_form_row_warns_when_an_admin_enters_a_client_ignored_key(): void
    {
        $strategy = Strategy::create(['name' => 'P', 'enabled' => true, 'options' => [], 'modified_at' => 1]);

        $this->actingAs($this->admin())
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'P', 'enabled' => 1,
                'option_keys' => ['stop-service', 'enable-audio'],
                'option_values' => ['Y', 'N'],
            ])
            ->assertOk()
            ->assertJsonPath('warning', 'RustDesk 1.5.3+ ignores stop-service when pushed by a server; set it at install time with --option or the config string.');

        // The value is still stored: clients older than 1.5.3 honour it.
        $this->assertSame('Y', $strategy->refresh()->options['stop-service']);

        // A catalog key that carries the warning inline does not repeat it in the response.
        $this->actingAs($this->admin())
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'P', 'enabled' => 1,
                'opt' => ['enable-trusted-devices' => 'N'],
            ])
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_api_update_also_reports_a_client_ignored_key(): void
    {
        [$plain, $prefix, $hash] = ApiKey::generateSecret();
        ApiKey::create([
            'user_id' => $this->admin()->id,
            'name' => 'Strategy writer',
            'token_hash' => $hash,
            'prefix' => $prefix,
            'scopes' => ['strategies.write'],
        ]);
        $strategy = Strategy::create(['name' => 'P', 'enabled' => true, 'options' => [], 'modified_at' => 1]);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->putJson('/api/v1/strategies/'.$strategy->id, ['options' => ['preset-note' => 'hello']])
            ->assertOk()
            ->assertJsonPath('data.options.preset-note', 'hello')
            ->assertJsonPath('warning', 'RustDesk 1.5.3+ ignores preset-note when pushed by a server; set it at install time with --option or the config string.');
    }

    public function test_removed_linux_headless_option_stays_loadable_preserved_and_unpushed(): void
    {
        $device = Device::create(['rustdesk_id' => 'legacy-1', 'uuid' => 'legacy-uuid', 'approved' => true]);
        $strategy = Strategy::create([
            'name' => 'Legacy',
            'enabled' => true,
            'options' => ['allow-linux-headless' => 'Y', 'enable-clipboard' => 'N'],
            'modified_at' => 1782181674,
        ]);
        StrategyAssignment::create([
            'strategy_id' => $strategy->id,
            'target_type' => StrategyAssignment::TARGET_DEVICE,
            'target_id' => $device->id,
        ]);

        // The editor still opens it and says the client no longer defines it.
        $this->actingAs($this->admin())->get(route('admin.strategies.edit', $strategy))
            ->assertOk()
            ->assertSee('Removed upstream')
            ->assertSee('allow-linux-headless')
            ->assertSee('Removed upstream in RustDesk 1.5.0');

        // The heartbeat no longer pushes it, but keeps pushing the rest.
        $res = $this->postJson('/api/heartbeat', ['id' => 'legacy-1', 'uuid' => 'legacy-uuid', 'modified_at' => 0]);
        $res->assertOk();
        $this->assertStringNotContainsString('allow-linux-headless', $res->getContent());
        $res->assertJsonPath('strategy.config_options.enable-clipboard', 'N');

        // Saving unrelated changes keeps the stored value untouched (no silent rewrite).
        $this->actingAs($this->admin())
            ->putJson(route('admin.strategies.update', $strategy), ['name' => 'Legacy', 'enabled' => 1, 'opt' => ['enable-clipboard' => 'Y']])
            ->assertOk();
        $strategy->refresh();
        $this->assertSame('Y', $strategy->options['allow-linux-headless']);
        $this->assertSame('Y', $strategy->options['enable-clipboard']);

        // Only an explicit tick removes it.
        $this->actingAs($this->admin())
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Legacy', 'enabled' => 1, 'opt' => ['enable-clipboard' => 'Y'],
                'drop_retired' => ['allow-linux-headless'],
            ])
            ->assertOk();
        $this->assertArrayNotHasKey('allow-linux-headless', $strategy->refresh()->options);
    }

    public function test_drop_retired_cannot_delete_anything_else(): void
    {
        $strategy = Strategy::create([
            'name' => 'P', 'enabled' => true, 'modified_at' => 1,
            'options' => ['enable-audio' => 'N', 'allow-linux-headless' => 'Y'],
        ]);

        $this->actingAs($this->admin())
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'P', 'enabled' => 1,
                'drop_retired' => ['enable-audio'],
            ])
            ->assertOk();

        $strategy->refresh();
        $this->assertArrayHasKey('allow-linux-headless', $strategy->options);
        // A posted catalog value survives; absence of an opt[] field still means "leave default".
        $this->assertArrayNotHasKey('enable-audio', $strategy->options);
    }

    public function test_delegated_editor_cannot_enable_drm_capture(): void
    {
        $inside = Group::create(['name' => 'Inside', 'type' => Group::TYPE_DEFAULT]);
        $delegate = User::create([
            'username' => 'delegate', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL, 'group_id' => $inside->id,
        ]);
        $delegate->adminRoles()->attach(AdminRole::create([
            'name' => 'Scoped', 'type' => AdminRole::TYPE_GROUP, 'scope' => [$inside->id],
            'perms' => ['strategies.view', 'strategies.edit'],
        ]));

        $device = Device::create([
            'rustdesk_id' => 'inside-peer', 'uuid' => 'uuid-inside', 'user_id' => $delegate->id, 'approved' => true,
        ]);
        $strategy = Strategy::create(['name' => 'Inside policy', 'enabled' => true, 'options' => [], 'modified_at' => 1]);
        $strategy->assignments()->create(['target_type' => StrategyAssignment::TARGET_DEVICE, 'target_id' => $device->id]);

        $this->actingAs($delegate)
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Inside policy', 'enabled' => 1,
                'opt' => ['allow-drm-capture' => 'Y'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.opt.0', 'Only a full administrator can set or change: allow-drm-capture.');

        $this->assertSame([], $strategy->refresh()->options);
    }
}
