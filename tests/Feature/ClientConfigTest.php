<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClientConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame(
            'rustdesk-host=h,key=k,api=a,relay=r.exe',
            (new ClientConfigService)->installerFilename('h', 'r', 'a', 'k'),
        );
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
}
