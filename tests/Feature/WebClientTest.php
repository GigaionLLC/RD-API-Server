<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\Device;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The browser remote desktop's server side.
 *
 * Two things are worth pinning here. The device list is an authorization boundary rather
 * than a filtered view, so both viewer routes must re-run the scope instead of trusting
 * route-model binding — a bare primary key in a URL is not a permission. And the setup
 * steps have to announce themselves: an HTTPS console with no `wss` endpoints renders a
 * viewer that looks ready and then fails to connect, which reads as an unreachable server
 * rather than a missing setting.
 */
class WebClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The routes report missing assets before anything else, which would mask every
        // other assertion in this file. The published copy is generated at image build
        // time and is absent from a source checkout.
        if (! File::isFile(public_path('assets/webclient/ui/viewer.js'))) {
            File::ensureDirectoryExists(public_path('assets/webclient/ui'));
            File::put(public_path('assets/webclient/ui/viewer.js'), "// test stub\n");
            File::put(public_path('assets/webclient/ui/viewer.html'),
                "<!doctype html><html><body><script type=\"module\" src=\"./viewer.js\"></script></body></html>\n");
            $this->stubbedAssets = true;
        }

        config([
            'rustdesk.id_server' => 'rustdesk.example.com:21116',
            'rustdesk.relay_server' => 'rustdesk.example.com:21117',
            // Cleared per test: the container this suite runs in may have either
            // transport configured, and a leaked value would silently satisfy the
            // assertions that exist to prove a missing one is reported.
            'rustdesk.web_client.ws_id_url' => '',
            'rustdesk.web_client.ws_relay_url' => '',
            'rustdesk.web_client.ws_id_upstream' => '',
            'rustdesk.web_client.ws_relay_upstream' => '',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->stubbedAssets) {
            File::deleteDirectory(public_path('assets/webclient'));
        }

        parent::tearDown();
    }

    private bool $stubbedAssets = false;

    /* ---------------------------------------------------------------- */
    /* Authorization */
    /* ---------------------------------------------------------------- */

    public function test_a_device_outside_the_operators_scope_is_not_reachable_by_id(): void
    {
        // Route-model binding alone would open any device to anyone who can guess a key.
        // 404 rather than 403: whether that device exists is itself outside their scope.
        [$delegate, $inside, $outside] = $this->scopedFixture();

        // Connect hands the id to the remote control screen rather than opening a session.
        $this->actingAs($delegate)->get(route('admin.devices.connect', $inside))
            ->assertRedirect(route('admin.remote', ['peer' => $inside->rustdesk_id]));
        $this->actingAs($delegate)->get(route('admin.devices.connect', $outside))->assertNotFound();

        // The iframe document is a second entry point and enforces the same boundary; it
        // carries the connection material, so a gap here would leak more than the page.
        $this->actingAs($delegate)->get(route('admin.devices.connect.frame', $inside))->assertOk();
        $this->actingAs($delegate)->get(route('admin.devices.connect.frame', $outside))->assertNotFound();
    }

    public function test_an_account_without_console_access_is_sent_to_the_login_screen(): void
    {
        // This is a browser console, so a denied GET redirects rather than returning a bare
        // 403. Each case gets its own test: the middleware calls Auth::logout() on the way
        // out, so a second request in the same test would be unauthenticated for a reason
        // that has nothing to do with what is being asserted.
        [, $inside] = $this->scopedFixture();
        $stranger = $this->user('stranger');

        $this->actingAs($stranger)
            ->get(route('admin.remote'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_the_viewer_frame_is_gated_the_same_way_as_the_page(): void
    {
        // The iframe document is a second entry point and carries the connection material,
        // so it must not have been given a weaker gate than the page that embeds it.
        [, $inside] = $this->scopedFixture();
        $stranger = $this->user('stranger');

        $this->actingAs($stranger)
            ->get(route('admin.devices.connect.frame', $inside))
            ->assertRedirect(route('admin.login'));
    }

    public function test_an_operator_holding_other_permissions_cannot_open_the_viewer(): void
    {
        // Console access is not device access. An auditor lands back on the dashboard.
        $auditGroup = Group::create(['name' => 'Audit', 'type' => Group::TYPE_DEFAULT]);
        $auditor = $this->user('auditor', $auditGroup);
        $auditor->adminRoles()->attach(AdminRole::create([
            'name' => 'Audit only',
            'type' => AdminRole::TYPE_GROUP,
            'scope' => [$auditGroup->id],
            'perms' => ['audit.view'],
        ]));

        [, $inside] = $this->scopedFixture();

        $this->actingAs($auditor)
            ->get(route('admin.remote'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_viewer_document_never_carries_a_peer_password(): void
    {
        // The peer's connection password is typed into the viewer by the operator. This
        // server does not hold it, and must not acquire the habit of shipping one.
        $admin = $this->admin();
        $device = $this->device('345890346', $admin, 'Workstation');

        $html = $this->actingAs($admin)
            ->get(route('admin.devices.connect.frame', $device))
            ->assertOk()
            ->getContent();

        // Assert against the injected config, not the whole document: the viewer's own
        // markup legitimately contains a password *field* for the operator to type into.
        $this->assertMatchesRegularExpression('/window\.RD_CONFIG=(\{.*?\});/s', $html);
        preg_match('/window\.RD_CONFIG=(\{.*?\});/s', $html, $matches);
        $injected = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('password', $injected);
    }

    /* ---------------------------------------------------------------- */
    /* Setup steps that would otherwise fail silently */
    /* ---------------------------------------------------------------- */

    public function test_an_https_console_without_ws_endpoints_says_so(): void
    {
        // The failure this prevents: the page renders, the operator presses Connect, and
        // the viewer reports that it cannot reach a server that is running perfectly well.
        // hbbs and hbbr speak plain ws, and a secure page cannot open a ws:// socket.
        config(['app.url' => 'https://console.example.com']);
        config(['rustdesk.web_client.ws_id_url' => '', 'rustdesk.web_client.ws_relay_url' => '']);

        $admin = $this->admin();
        $device = $this->device('345890346', $admin, 'Workstation');

        $this->actingAs($admin)
            ->get(route('admin.remote', ['peer' => $device->rustdesk_id]))
            ->assertOk()
            ->assertSee('WebSocket endpoints are not configured')
            // The remote screen points at the diagnostics page rather than repeating the
            // environment names, so there is one place that explains how to set them.
            ->assertSee(route('admin.web-client.diagnostics'), false);
    }

    public function test_half_a_configuration_is_reported_as_missing(): void
    {
        // One URL alone is ignored by the client, which would otherwise give a rendezvous
        // endpoint with no matching relay — a session that connects and then stops.
        config(['app.url' => 'https://console.example.com']);
        config([
            'rustdesk.web_client.ws_id_url' => 'wss://console.example.com/ws/id',
            'rustdesk.web_client.ws_relay_url' => '',
        ]);

        $admin = $this->admin();
        $device = $this->device('345890346', $admin, 'Workstation');

        $this->actingAs($admin)
            ->get(route('admin.remote', ['peer' => $device->rustdesk_id]))
            ->assertOk()
            ->assertSee('WebSocket endpoints are not configured');
    }

    public function test_a_configured_https_console_shows_no_warning(): void
    {
        config(['app.url' => 'https://console.example.com']);
        config([
            'rustdesk.web_client.ws_id_url' => 'wss://console.example.com/ws/id',
            'rustdesk.web_client.ws_relay_url' => 'wss://console.example.com/ws/relay',
        ]);

        $admin = $this->admin();
        $device = $this->device('345890346', $admin, 'Workstation');

        $this->actingAs($admin)
            ->get(route('admin.remote', ['peer' => $device->rustdesk_id]))
            ->assertOk()
            ->assertDontSee('WebSocket endpoints are not configured')
            ->assertSee('rd-viewer');
    }

    public function test_a_plain_http_console_is_not_asked_for_wss(): void
    {
        // localhost is a secure context without TLS, which is what makes a local
        // evaluation work with no proxy at all. Demanding wss there would be wrong.
        config(['app.url' => 'http://localhost']);
        config(['rustdesk.web_client.ws_id_url' => '', 'rustdesk.web_client.ws_relay_url' => '']);

        $admin = $this->admin();
        $device = $this->device('345890346', $admin, 'Workstation');

        $this->actingAs($admin)
            ->get(route('admin.remote', ['peer' => $device->rustdesk_id]))
            ->assertOk()
            ->assertDontSee('WebSocket endpoints are not configured');
    }

    public function test_proxied_transport_derives_its_endpoints_from_the_console_origin(): void
    {
        // The point of this mode: the operator sets two upstreams and nothing else. Asking
        // for the URLs again would be a second place to get wrong, and they can only ever
        // be this console's own origin — that is where the Nginx locations are served.
        config(['app.url' => 'https://console.example.com']);
        config([
            'rustdesk.web_client.ws_id_url' => '',
            'rustdesk.web_client.ws_relay_url' => '',
            'rustdesk.web_client.ws_id_upstream' => 'hbbs:21118',
            'rustdesk.web_client.ws_relay_upstream' => 'hbbr:21119',
        ]);

        $admin = $this->admin();
        $device = $this->device('345890346', $admin, 'Workstation');

        $html = $this->actingAs($admin)
            ->get(route('admin.devices.connect.frame', $device))
            ->assertOk()
            ->getContent();

        preg_match('/window\.RD_CONFIG=(\{.*?\});/s', $html, $matches);
        $injected = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('wss://console.example.com/ws/id', $injected['rendezvousUrl']);
        $this->assertSame('wss://console.example.com/ws/relay', $injected['relayUrl']);

        // And the page must not then ask for configuration that is already in place.
        $this->actingAs($admin)
            ->get(route('admin.remote', ['peer' => $device->rustdesk_id]))
            ->assertOk()
            ->assertDontSee('WebSocket endpoints are not configured');
    }

    public function test_explicit_urls_win_over_the_proxied_transport(): void
    {
        // A deployment that terminates TLS in front of hbbs itself has already said where
        // those endpoints are, and they may not be on this hostname at all.
        config(['app.url' => 'https://console.example.com']);
        config([
            'rustdesk.web_client.ws_id_url' => 'wss://edge.example.com/ws/id',
            'rustdesk.web_client.ws_relay_url' => 'wss://edge.example.com/ws/relay',
            'rustdesk.web_client.ws_id_upstream' => 'hbbs:21118',
            'rustdesk.web_client.ws_relay_upstream' => 'hbbr:21119',
        ]);

        $admin = $this->admin();
        $device = $this->device('345890346', $admin, 'Workstation');

        $html = $this->actingAs($admin)
            ->get(route('admin.devices.connect.frame', $device))
            ->assertOk()
            ->getContent();

        preg_match('/window\.RD_CONFIG=(\{.*?\});/s', $html, $matches);
        $injected = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('wss://edge.example.com/ws/id', $injected['rendezvousUrl']);
    }

    public function test_half_an_upstream_configuration_does_not_derive_endpoints(): void
    {
        // Nginx would render one location and the session would connect and then stop.
        // The runtime configuration script refuses to boot on this; the warning here is
        // what a source deployment sees.
        config(['app.url' => 'https://console.example.com']);
        config([
            'rustdesk.web_client.ws_id_url' => '',
            'rustdesk.web_client.ws_relay_url' => '',
            'rustdesk.web_client.ws_id_upstream' => 'hbbs:21118',
            'rustdesk.web_client.ws_relay_upstream' => '',
        ]);

        $admin = $this->admin();
        $device = $this->device('345890346', $admin, 'Workstation');

        $this->actingAs($admin)
            ->get(route('admin.remote', ['peer' => $device->rustdesk_id]))
            ->assertOk()
            ->assertSee('WebSocket endpoints are not configured');
    }

    /* ---------------------------------------------------------------- */
    /* Diagnostics */
    /* ---------------------------------------------------------------- */

    public function test_diagnostics_names_every_blocking_condition(): void
    {
        // The page exists because all of these present identically from the browser — a
        // connection error against a server that is running fine — and none of them appear
        // in a log.
        config(['app.url' => 'http://console.example.com']);   // not a secure context
        config(['rustdesk.id_server' => '']);                  // no rendezvous host
        config(['rustdesk.key' => '', 'rustdesk.key_file' => '']);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.web-client.diagnostics'))
            ->assertOk()
            ->assertSee('will not connect yet')
            ->assertSee('Secure context')
            ->assertSee('RUSTDESK_ID_SERVER')
            ->assertSee('No WebSocket transport configured', false);
    }

    public function test_diagnostics_reports_a_healthy_explicit_deployment(): void
    {
        config(['app.url' => 'https://console.example.com']);
        config([
            'rustdesk.web_client.ws_id_url' => 'wss://console.example.com/ws/id',
            'rustdesk.web_client.ws_relay_url' => 'wss://console.example.com/ws/relay',
            'rustdesk.key' => 'a-public-server-key',
        ]);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.web-client.diagnostics'))
            ->assertOk()
            ->assertSee('Every server-side check passed')
            // The browser probe is the point: reachability through the operator's own proxy
            // is not something this server can determine for them.
            ->assertSee('Test from this browser');
    }

    public function test_diagnostics_reports_an_unreachable_upstream(): void
    {
        // A name that does not resolve, a container on another network, a closed port —
        // one symptom, and this is the only check that separates them.
        config(['app.url' => 'https://console.example.com']);
        config([
            'rustdesk.web_client.ws_id_url' => '',
            'rustdesk.web_client.ws_relay_url' => '',
            'rustdesk.web_client.ws_id_upstream' => 'no-such-host.invalid:21118',
            'rustdesk.web_client.ws_relay_upstream' => 'no-such-host.invalid:21119',
        ]);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.web-client.diagnostics'))
            ->assertOk()
            ->assertSee('did not accept a connection', false)
            ->assertSee('share a network');
    }

    public function test_diagnostics_requires_the_settings_permission(): void
    {
        $stranger = $this->user('stranger');

        $this->actingAs($stranger)
            ->get(route('admin.web-client.diagnostics'))
            ->assertRedirect(route('admin.login'));
    }

    /* ---------------------------------------------------------------- */
    /* Fixtures */
    /* ---------------------------------------------------------------- */

    /** @return array{0: User, 1: Device, 2: Device} */
    private function scopedFixture(array $permissions = ['devices.view']): array
    {
        $insideGroup = Group::create(['name' => 'Inside scope', 'type' => Group::TYPE_DEFAULT]);
        $outsideGroup = Group::create(['name' => 'Outside scope', 'type' => Group::TYPE_DEFAULT]);

        $delegate = $this->user('delegate', $insideGroup);
        $insideOwner = $this->user('inside-user', $insideGroup);
        $outsideOwner = $this->user('outside-user', $outsideGroup);

        $delegate->adminRoles()->attach(AdminRole::create([
            'name' => 'Scoped operations',
            'type' => AdminRole::TYPE_GROUP,
            'scope' => [$insideGroup->id],
            'perms' => $permissions,
        ]));

        return [
            $delegate,
            $this->device('inside-peer', $insideOwner, 'Inside workstation'),
            $this->device('outside-peer', $outsideOwner, 'Outside workstation'),
        ];
    }

    private function admin(): User
    {
        $user = $this->user('operator');
        $user->is_admin = true;
        $user->save();

        return $user;
    }

    private function user(string $username, ?Group $group = null): User
    {
        return User::create([
            'username' => $username,
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
            'group_id' => $group?->id,
        ]);
    }

    private function device(string $peerId, User $owner, string $hostname): Device
    {
        return Device::create([
            'rustdesk_id' => $peerId,
            'uuid' => 'uuid-'.$peerId,
            'user_id' => $owner->id,
            'hostname' => $hostname,
        ]);
    }
}
