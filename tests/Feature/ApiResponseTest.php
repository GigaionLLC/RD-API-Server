<?php

namespace Tests\Feature;

use App\Models\DeployToken;
use App\Models\Device;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the response-shape contract the RustDesk client depends on:
 * success acks must be JSON objects ({}), while list endpoints stay arrays ([]).
 * Also guards that strategy options survive a save (the key/value array round-trip).
 */
class ApiResponseTest extends TestCase
{
    use RefreshDatabase;

    private function clientToken(): string
    {
        User::create([
            'username' => 'cli', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL,
        ]);

        return $this->postJson('/api/login', [
            'username' => 'cli', 'password' => 'secret12345', 'id' => 'd1', 'uuid' => 'u1',
        ])->json('access_token');
    }

    public function test_address_book_mutation_ack_is_an_empty_200(): void
    {
        $res = $this->withHeader('Authorization', 'Bearer '.$this->clientToken())
            ->postJson('/api/ab/peer/add/personal', ['id' => '900', 'alias' => 'PC']);

        $res->assertOk();
        // The client's _jsonDecodeActionResp treats success as HTTP 200 + EMPTY body.
        // "{}" → null.toString() → "null" shown as an error; "[]" → "[]" shown. Must be empty.
        $this->assertSame('', $res->getContent());
    }

    public function test_login_options_stays_an_array(): void
    {
        $res = $this->getJson('/api/login-options');
        $res->assertOk();
        $this->assertSame('[]', $res->getContent()); // list endpoint: empty array, not "{}"
    }

    public function test_audit_endpoints_return_objects(): void
    {
        Device::create(['rustdesk_id' => 'p1', 'uuid' => 'u1']);

        $this->assertSame('{}', $this->postJson('/api/audit/conn', [
            'id' => 'p1', 'uuid' => 'u1', 'action' => 'new', 'conn_id' => 1,
            'session_id' => 1,
        ])->getContent());
        $this->assertSame('{}', $this->postJson('/api/audit/alarm', [
            'id' => 'p1', 'uuid' => 'u1', 'typ' => 0, 'info' => 'x',
        ])->getContent());
    }

    public function test_deploy_returns_a_json_object_with_a_result_field(): void
    {
        // The client JSON-parses this and reads `result`; a bare text body breaks it.
        $res = $this->postJson('/api/devices/deploy', ['id' => 'x', 'uuid' => 'u', 'pk' => 'p']);

        $res->assertOk()->assertJsonStructure(['result']);
        $this->assertIsString($res->json('result'));
    }

    public function test_peer_force_always_relay_is_serialised_as_a_string(): void
    {
        $token = $this->clientToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/ab/peer/add/personal', ['id' => '555', 'forceAlwaysRelay' => 'true'])
            ->assertOk();

        $res = $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/ab/peers');
        $res->assertOk();

        $peer = collect($res->json('data'))->firstWhere('id', '555');
        // Client does `json['forceAlwaysRelay'] == 'true'` — must be the string, not a bool.
        $this->assertSame('true', $peer['forceAlwaysRelay']);
    }

    public function test_audit_active_guid_then_note_roundtrip(): void
    {
        $token = $this->clientToken();
        Device::create(['rustdesk_id' => 'host1', 'uuid' => 'host-uuid']);

        // Controlled host opens a connection (unauthenticated audit ingest).
        $this->postJson('/api/audit/conn', [
            'id' => 'host1', 'uuid' => 'host-uuid', 'action' => 'new', 'conn_id' => 7,
            'session_id' => 'sess-9',
        ])->assertOk();

        // Controlling client fetches the live session's guid (bare JSON string).
        $guid = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/audit/conn/active?id=host1&session_id=sess-9&conn_type=0')
            ->assertOk()
            ->json();
        $this->assertIsString($guid);
        $this->assertNotEmpty($guid);

        // ...then attaches an end-of-connection note keyed on that guid.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/audit', ['guid' => $guid, 'note' => 'handled ticket #42'])
            ->assertOk();

        $this->assertDatabaseHas('audit_conns', ['guid' => $guid, 'note' => 'handled ticket #42']);
    }

    public function test_cli_assign_registers_device_and_applies_presets(): void
    {
        $owner = User::create([
            'username' => 'ops', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL,
            'is_admin' => true,
        ]);
        $token = DeployToken::create(['user_id' => $owner->id, 'token' => 'deploy-xyz', 'name' => 'CLI']);
        $strategy = Strategy::create(['name' => 'Locked', 'enabled' => true, 'options' => [], 'modified_at' => 1]);

        $res = $this->withHeader('Authorization', 'Bearer deploy-xyz')
            ->postJson('/api/devices/cli', [
                'id' => 'dev-42',
                'uuid' => 'u-42',
                'strategy_name' => 'Locked',
                'device_group_name' => 'Warehouse',
                'device_name' => 'Front Desk',
            ]);

        $res->assertOk();
        $this->assertSame('', $res->getContent()); // empty body ⇒ client prints "Done!"

        $device = Device::where('rustdesk_id', 'dev-42')->first();
        $this->assertNotNull($device);
        $this->assertSame($owner->id, $device->user_id);
        $this->assertSame($strategy->id, $device->strategy_id);
        $this->assertSame('Front Desk', $device->device_name);
        $this->assertTrue((bool) $device->approved);
        $this->assertDatabaseHas('device_groups', ['name' => 'Warehouse']);
    }

    public function test_cli_assign_rejects_a_bad_token(): void
    {
        $res = $this->withHeader('Authorization', 'Bearer nope')
            ->postJson('/api/devices/cli', ['id' => 'dev-9', 'uuid' => 'u-9']);

        $res->assertOk();
        $this->assertStringContainsStringIgnoringCase('token', $res->getContent());
        $this->assertDatabaseMissing('devices', ['rustdesk_id' => 'dev-9']);
    }

    public function test_strategy_update_preserves_options(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        $strategy = Strategy::create(['name' => 'P', 'enabled' => true, 'options' => [], 'modified_at' => 1]);

        // Mirrors what the fixed live-save sends: parallel option_keys / option_values arrays.
        $this->actingAs($admin)->put(route('admin.strategies.update', $strategy), [
            'name' => 'P',
            'enabled' => 1,
            'option_keys' => ['enable-audio', 'enable-clipboard'],
            'option_values' => ['N', 'Y'],
        ])->assertOk();

        $strategy->refresh();
        $this->assertSame(['enable-audio' => 'N', 'enable-clipboard' => 'Y'], $strategy->options);
    }

    public function test_strategy_update_merges_known_and_custom_options(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        $strategy = Strategy::create(['name' => 'P', 'enabled' => true, 'options' => [], 'modified_at' => 1]);

        $this->actingAs($admin)->put(route('admin.strategies.update', $strategy), [
            'name' => 'P',
            'enabled' => 1,
            // Catalog options (assoc). Empty value = "client default" and must be dropped.
            'opt' => [
                'enable-audio' => 'N',
                'access-mode' => 'view',
                'enable-clipboard' => '',          // Default → omitted
                'auto-disconnect-timeout' => '15',
            ],
            // Custom (non-catalog) rows.
            'option_keys' => ['my-custom-key', ''],
            'option_values' => ['hello', 'ignored'],
        ])->assertOk();

        $strategy->refresh();
        $this->assertSame([
            'enable-audio' => 'N',
            'access-mode' => 'view',
            'auto-disconnect-timeout' => '15',
            'my-custom-key' => 'hello',
        ], $strategy->options);
        $this->assertArrayNotHasKey('enable-clipboard', $strategy->options);
    }

    public function test_strategy_edit_page_renders_the_option_catalog(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        $strategy = Strategy::create([
            'name' => 'P', 'enabled' => true,
            'options' => ['enable-audio' => 'N', 'some-unlisted-key' => '7'], 'modified_at' => 1,
        ]);

        $this->actingAs($admin)->get(route('admin.strategies.edit', $strategy))
            ->assertOk()
            ->assertSee('Permissions')           // a catalog group
            ->assertSee('opt[enable-keyboard]')   // a known control
            ->assertSee('Custom options');        // the unlisted key falls here
    }
}
