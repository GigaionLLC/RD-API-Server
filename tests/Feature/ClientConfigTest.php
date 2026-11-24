<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\Strategy;
use App\Models\User;
use App\Services\ClientConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The Client Config generator: the encoded server-config string must round-trip exactly the
 * way the RustDesk client decodes it (reverse → url-safe base64 → JSON {host,relay,api,key}).
 */
class ClientConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_string_round_trips_like_the_client(): void
    {
        $svc = new ClientConfigService;
        $cs = $svc->configString('h.example.com', 'r.example.com', 'https://api.example.com', 'KEY123');

        // Exactly what the client does in ServerConfig.decode / custom_server.rs.
        $json = json_decode(base64_decode(strtr(strrev($cs), '-_', '+/')), true);

        $this->assertSame('h.example.com', $json['host']);
        $this->assertSame('r.example.com', $json['relay']);
        $this->assertSame('https://api.example.com', $json['api']);
        $this->assertSame('KEY123', $json['key']);
    }

    public function test_qr_payload_is_prefixed_with_config(): void
    {
        $this->assertStringStartsWith('config=', (new ClientConfigService)->qrPayload('h', 'r', 'a', 'k'));
    }

    public function test_installer_filename_matches_the_client_parser(): void
    {
        // host first (the client requires it), then key/relay, and a trailing "," terminator.
        // The API server is never included: an api= value needs a scheme and Windows file names
        // reject ':' and '/'.
        $this->assertSame(
            'rustdesk-host=h,key=k,relay=r,.exe',
            (new ClientConfigService)->installerFilename('h', 'r', 'a', 'k'),
        );
    }

    public function test_installer_filename_is_a_usable_windows_file_name(): void
    {
        $svc = new ClientConfigService;
        // A realistic hbbs key: standard base64, so '=' padding and '+' are expected (a '/'
        // cannot appear in a file name and is covered separately below).
        $key = 'abcDEF0123456789+xyzABCdef0123456789xyzABC=';
        $name = $svc->installerFilename('rd.example.com', 'relay.example.com', 'https://api.example.com', $key);

        $this->assertDoesNotMatchRegularExpression('/[<>:"\/\\\\|?*\x00-\x1F]/', $name);
        $this->assertStringEndsWith(',.exe', $name);
        $this->assertSame(
            ['host' => 'rd.example.com', 'key' => $key, 'api' => '', 'relay' => 'relay.example.com'],
            $this->parseInstallerName($name),
        );
    }

    public function test_installer_filename_survives_a_duplicate_download_suffix(): void
    {
        // Windows renames a second copy to "… (1).exe". The trailing comma keeps the last value
        // (relay here) intact instead of letting the suffix become part of it.
        $name = (new ClientConfigService)->installerFilename('rd.example.com', 'relay.example.com', '', 'K');

        $this->assertSame(
            ['host' => 'rd.example.com', 'key' => 'K', 'api' => '', 'relay' => 'relay.example.com'],
            $this->parseInstallerName(substr($name, 0, -4).' (1).exe'),
        );
    }

    public function test_installer_filename_problems_block_impossible_file_names(): void
    {
        $svc = new ClientConfigService;

        // '=' padding and '+' are fine.
        $this->assertSame([], $svc->installerFilenameProblems('h.example.com', 'r.example.com', 'abc+def='));

        // '/' in a base64 key cannot exist in a Windows file name, and must not be stripped
        // (that would silently break server authentication).
        $problems = $svc->installerFilenameProblems('h.example.com', 'r.example.com', 'ab/cd=');
        $this->assertArrayHasKey('key', $problems);
        $this->assertStringContainsString('Public key', $problems['key']);

        // A host with a port is only kept out because ':' is illegal too.
        $this->assertArrayHasKey('host', $svc->installerFilenameProblems('h.example.com:21116', '', ''));

        // No host at all: the client cannot parse the comma form.
        $this->assertArrayHasKey('host', $svc->installerFilenameProblems('', 'r.example.com', 'K'));

        // The API server is not a filename field, so it is never a problem.
        $this->assertSame([], $svc->installerFilenameProblems('h.example.com', '', 'K'));
    }

    public function test_page_refuses_a_renamed_installer_for_a_key_windows_cannot_store(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.client-config.generate'), [
                'host' => 'rd.example.com',
                'key' => 'ab/cd=',
            ])
            ->assertOk()
            ->assertDontSee('rustdesk-host=')
            ->assertSee('Windows forbids in a file name')
            ->assertSee('Use the config string or the');
    }

    public function test_page_renders_an_svg_qr_for_an_admin(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.client-config.index', ['host' => 'h.example.com', 'key' => 'K']))
            ->assertOk()
            ->assertSee('Config string')
            ->assertSee('<svg', false);
    }

    public function test_unlock_pin_renders_per_os_set_commands(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.client-config.generate'), ['unlock_pin' => '4821'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee("--set-unlock-pin '4821'")
            ->assertSee('$env:ProgramFiles')
            ->assertSee('cannot', false); // explains it can't be pushed by a strategy
    }

    public function test_unlock_pin_is_ignored_in_get_urls_and_the_form_posts_with_csrf(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.client-config.index', ['unlock_pin' => '4821']));

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('--set-unlock-pin')
            ->assertDontSee('value="4821"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('action="'.route('admin.client-config.generate').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="unlock_pin" type="password"', false);

        $route = Route::getRoutes()->getByName('admin.client-config.generate');
        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('permission:deploy.view', $route->gatherMiddleware());

        // Even a POST cannot smuggle the PIN through its URL; only the request body is read.
        $this->actingAs($admin)
            ->post(route('admin.client-config.generate', ['unlock_pin' => '4821']))
            ->assertOk()
            ->assertDontSee('--set-unlock-pin');
    }

    public function test_unlock_pin_validation_rejects_shell_metacharacters_without_flashing_the_pin(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        $hostilePins = [
            '1234;id', '1234&id', '1234|id', '"1234"', "1234'id", "1234\r\nid",
            '$(id)', '`id`', '%PATH%', '!PATH!',
        ];

        foreach ($hostilePins as $pin) {
            $this->actingAs($admin)
                ->post(route('admin.client-config.generate'), ['unlock_pin' => $pin])
                ->assertRedirect(route('admin.client-config.index'))
                ->assertSessionHasErrors('unlock_pin')
                ->assertSessionMissing('_old_input.unlock_pin');
        }
    }

    public function test_generation_post_requires_deploy_view_permission(): void
    {
        $role = AdminRole::create([
            'name' => 'Dashboard viewer',
            'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [],
            'perms' => ['dashboard.view'],
        ]);
        $delegate = User::create([
            'username' => 'delegate', 'password' => 'secret12345',
            'is_admin' => false, 'status' => User::STATUS_NORMAL,
        ]);
        $delegate->adminRoles()->attach($role);

        $this->actingAs($delegate)
            ->post(route('admin.client-config.generate'), ['unlock_pin' => '4821'])
            ->assertForbidden();
    }

    public function test_generation_post_rejects_a_missing_csrf_token(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        // Laravel bypasses CSRF while APP_ENV=testing. Temporarily use a non-test environment
        // so this request exercises the real web middleware instead of only inspecting it.
        $this->app->instance('env', 'local');

        try {
            $this->actingAs($admin)
                ->withSession(['_token' => 'expected-token'])
                ->post(route('admin.client-config.generate'), ['unlock_pin' => '4821'])
                ->assertStatus(419);

            $this->actingAs($admin)
                ->withSession(['_token' => 'expected-token'])
                ->post(route('admin.client-config.generate'), [
                    '_token' => 'expected-token',
                    'unlock_pin' => '4821',
                ])
                ->assertOk();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_no_unlock_pin_card_without_a_pin(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.client-config.index'))
            ->assertOk()
            ->assertDontSee('--set-unlock-pin');
    }

    public function test_install_script_renders_option_commands_per_os(): void
    {
        $svc = new ClientConfigService;
        $scripts = $svc->installScript([
            'direct-server' => 'Y',
            'direct-access-port' => '21118',
            'enable-clipboard' => '',          // empty → skipped
            'whitelist' => '10.0.0.1 10.0.0.2', // spaces → quoted
        ], '2084502424');

        $this->assertStringContainsString("sudo rustdesk --set-unlock-pin '2084502424'", $scripts['Linux']);
        $this->assertStringContainsString("sudo rustdesk --option 'direct-server' 'Y'", $scripts['Linux']);
        $this->assertStringContainsString("--option 'direct-access-port' '21118'", $scripts['Linux']);
        $this->assertStringNotContainsString('enable-clipboard', $scripts['Linux']); // empty skipped
        $this->assertStringContainsString("--option 'whitelist' '10.0.0.1 10.0.0.2'", $scripts['Linux']);
        $this->assertStringContainsString('rustdesk.exe', $scripts['Windows']);
        $this->assertStringStartsWith('& "$env:ProgramFiles\\RustDesk\\rustdesk.exe"', $scripts['Windows']);
    }

    public function test_install_script_quotes_shell_metacharacters_for_posix_and_powershell(): void
    {
        $value = "alpha; beta & gamma | delta ' \" \$(id) `whoami` %PATH% !PATH! \$env:PATH";
        $scripts = (new ClientConfigService)->installScript(['safe-key' => $value]);
        $posixValue = "'".str_replace("'", "'\\''", $value)."'";
        $powerShellValue = "'".str_replace("'", "''", $value)."'";

        $this->assertSame("sudo rustdesk --option 'safe-key' {$posixValue}", $scripts['Linux']);
        $this->assertSame(
            '& "$env:ProgramFiles\\RustDesk\\rustdesk.exe" --option \'safe-key\' '.$powerShellValue,
            $scripts['Windows'],
        );
        $this->assertSame(1, substr_count($scripts['Linux'], "\n") + 1);
        $this->assertSame(1, substr_count($scripts['Windows'], "\n") + 1);
    }

    public function test_install_script_rejects_hostile_keys_pins_and_multiline_values(): void
    {
        $service = new ClientConfigService;
        $hostile = [
            'bad;id', 'bad&id', 'bad|id', 'bad key', '"bad"', "bad'id", "bad\r\nid",
            '$(id)', '`id`', '%PATH%', '!PATH!',
        ];

        foreach ($hostile as $key) {
            try {
                $service->installScript([$key => 'value']);
                $this->fail("Hostile option key was accepted: {$key}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach ($hostile as $pin) {
            try {
                $service->installScript([], $pin);
                $this->fail("Hostile unlock PIN was accepted: {$pin}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (["first\nsecond", "first\r\nsecond"] as $value) {
            try {
                $service->installScript(['safe-key' => $value]);
                $this->fail('A multiline option value was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_strategy_install_script_renders_for_admin(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        $strategy = Strategy::create([
            'name' => 'Locked', 'enabled' => true,
            'options' => ['enable-tunnel' => 'Y', 'enable-lan-discovery' => 'N'], 'modified_at' => 1,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.client-config.generate'), ['strategy' => $strategy->id, 'unlock_pin' => '4821'])
            ->assertOk()
            ->assertSee('Install script')
            ->assertSee("--option 'enable-tunnel' 'Y'")
            ->assertSee("--option 'enable-lan-discovery' 'N'")
            ->assertSee("--set-unlock-pin '4821'");
    }

    public function test_strategy_editor_rejects_unsafe_custom_option_keys_and_multiline_values(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        $strategy = Strategy::create([
            'name' => 'Locked', 'enabled' => true, 'options' => [], 'modified_at' => 1,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.strategies.edit', $strategy))
            ->put(route('admin.strategies.update', $strategy), [
                'name' => 'Locked',
                'option_keys' => ['safe-key', 'unsafe;id'],
                'option_values' => ['safe', "first\nsecond"],
            ])
            ->assertRedirect(route('admin.strategies.edit', $strategy))
            ->assertSessionHasErrors(['option_keys.1', 'option_values.1']);
    }

    public function test_local_parser_matches_the_upstream_client_test_vectors(): void
    {
        // Vectors copied from rustdesk/rustdesk src/custom_server.rs::test_filename_license_string,
        // so the round-trip assertions above are checked against the real client's expectations.
        $this->assertSame(
            ['host' => 'server.example.net', 'key' => '', 'api' => '', 'relay' => ''],
            $this->parseInstallerName('rustdesk-host=server.example.net.exe'),
        );
        $this->assertSame(
            ['host' => 'server.example.net', 'key' => 'Zm9vYmFyLiwyCg==', 'api' => 'abc', 'relay' => ''],
            $this->parseInstallerName('rustdesk-host=server.example.net,api=abc,key=Zm9vYmFyLiwyCg==.exe'),
        );
        $this->assertSame(
            ['host' => 'server.example.net', 'key' => 'Zm9vYmFyLiwyCg==', 'api' => '', 'relay' => 'server.example.net'],
            $this->parseInstallerName('rustdesk-Host=server.example.net,Key=Zm9vYmFyLiwyCg==,RELAY=server.example.net.exe'),
        );
    }

    /**
     * The parse the RustDesk client performs on a renamed installer
     * (src/custom_server.rs::get_custom_server_from_string, comma branch).
     *
     * @return array{host: string, key: string, api: string, relay: string}
     */
    private function parseInstallerName(string $fileName): array
    {
        $name = (string) preg_replace('/\.exe$/i', '', $fileName);
        $start = stripos($name, 'host=');
        $this->assertNotFalse($start, 'The client only parses the comma form when the name contains host=');

        $parsed = ['host' => '', 'key' => '', 'api' => '', 'relay' => ''];
        foreach (explode(',', substr($name, (int) $start)) as $part) {
            $lower = strtolower($part);
            foreach (['host' => 5, 'key' => 4, 'api' => 4, 'relay' => 6] as $field => $skip) {
                if (str_starts_with($lower, $field.'=')) {
                    $parsed[$field] = substr($part, $skip);
                }
            }
        }

        return $parsed;
    }
}
