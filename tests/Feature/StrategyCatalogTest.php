<?php

namespace Tests\Feature;

use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The strategy option catalog (config/strategy_options.php) covers the documented
 * client policy keys (KEYS_SETTINGS / KEYS_BUILDIN_SETTINGS), and newly-added options
 * render in the editor and persist on save.
 */
class StrategyCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'admin', 'password' => 'secret12345', 'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
    }

    public function test_catalog_includes_the_newly_added_policy_keys(): void
    {
        $keys = [];
        foreach (config('strategy_options.tabs') as $tab) {
            foreach ($tab['sections'] as $section) {
                foreach ($section['options'] as $opt) {
                    $keys[] = $opt['key'];
                }
            }
        }

        foreach ([
            'disable-udp', 'allow-https-21114', 'use-raw-tcp-for-api',
            'proxy-url', 'proxy-username', 'proxy-password',
            'one-way-file-transfer', 'one-way-clipboard-redirection', 'file-transfer-max-files',
            'allow-insecure-tls-fallback', 'allow-hostname-as-id', 'register-device',
            'allow-deep-link-password', 'allow-deep-link-server-settings',
            'default-connect-password', 'disable-unlock-pin', 'remove-preset-password-warning',
            'enable-directx-capture', 'keep-awake-during-incoming-sessions',
            'enable-perm-change-in-accept-window',
            'main-window-always-on-top', 'allow-command-line-settings-when-settings-disabled',
        ] as $key) {
            $this->assertContains($key, $keys, "Catalog is missing {$key}");
        }
    }

    public function test_editor_renders_new_options_and_proxy_section(): void
    {
        $strategy = Strategy::create(['name' => 'P', 'enabled' => true, 'options' => [], 'modified_at' => 1]);

        $this->actingAs($this->admin())->get(route('admin.strategies.edit', $strategy))
            ->assertOk()
            ->assertSee('opt[proxy-url]')
            ->assertSee('opt[disable-udp]')
            ->assertSee('Proxy');
    }

    public function test_new_options_persist_on_save(): void
    {
        $strategy = Strategy::create(['name' => 'P', 'enabled' => true, 'options' => [], 'modified_at' => 1]);

        $this->actingAs($this->admin())->put(route('admin.strategies.update', $strategy), [
            'name' => 'P', 'enabled' => 1,
            'opt' => [
                'disable-udp' => 'Y',
                'proxy-url' => 'socks5://10.0.0.1:1080',
                'one-way-file-transfer' => 'Y',
                'file-transfer-max-files' => '50',
            ],
        ])->assertOk();

        $strategy->refresh();
        $this->assertSame('Y', $strategy->options['disable-udp']);
        $this->assertSame('socks5://10.0.0.1:1080', $strategy->options['proxy-url']);
        $this->assertSame('Y', $strategy->options['one-way-file-transfer']);
        $this->assertSame('50', $strategy->options['file-transfer-max-files']);
    }
}
