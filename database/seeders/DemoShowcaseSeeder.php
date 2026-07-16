<?php

namespace Database\Seeders;

use App\Models\AddressBook;
use App\Models\AddressBookPeer;
use App\Models\Alarm;
use App\Models\ApiKey;
use App\Models\AuditConn;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Group;
use App\Models\LoginLog;
use App\Models\Strategy;
use App\Models\Tag;
use App\Models\User;
use App\Models\Webhook;
use App\Support\BootstrapAdminCredentials;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Populates a realistic, screenshot-ready demo dataset — devices, users, address books,
 * connection/audit history (including the RustDesk 1.4.9 auth-detail fields), alarms,
 * webhooks and API keys — so every admin page renders with lifelike content.
 *
 * NOT part of the default DatabaseSeeder: production installs stay clean. Run explicitly:
 *   php artisan db:seed --class=Database\\Seeders\\DemoShowcaseSeeder
 *
 * All data is fictional (RFC-5737 example IPs, example.com hosts). Idempotent-ish: keyed
 * records use updateOrCreate; the volume tables are only topped up when empty.
 */
class DemoShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        $configuredPassword = config('bootstrap.admin.password');
        $admin = User::where('username', 'admin')->first()
            ?? User::create([
                'username' => 'admin',
                'password' => BootstrapAdminCredentials::resolvePassword(
                    is_string($configuredPassword) ? $configuredPassword : null,
                    'admin',
                    app()->environment('production'),
                ),
                'is_admin' => true,
                'status' => User::STATUS_NORMAL,
                'display_name' => 'Administrator',
            ]);

        $users = $this->seedUsers();
        [$userGroups, $deviceGroups] = $this->seedGroups();
        $strategies = $this->seedStrategies();
        $devices = $this->seedDevices($admin, $users, $deviceGroups, $strategies);
        $this->seedConnectionAudit($devices);
        $this->seedAlarms($devices);
        $this->seedLoginLogs($admin, $users);
        $this->seedAddressBook($admin);
        $this->seedWebhooks();
        $this->seedApiKeys($admin);
    }

    /** @return array<int, User> */
    private function seedUsers(): array
    {
        $people = [
            ['username' => 'alice', 'display_name' => 'Alice Nguyen', 'email' => 'alice@example.com'],
            ['username' => 'bob', 'display_name' => 'Bob Martin', 'email' => 'bob@example.com'],
            ['username' => 'carol', 'display_name' => 'Carol Diaz', 'email' => 'carol@example.com'],
            ['username' => 'dave', 'display_name' => 'Dave Okoro', 'email' => 'dave@example.com'],
        ];

        return array_map(function (array $p): User {
            $user = User::firstOrCreate(
                ['username' => $p['username']],
                ['password' => 'demo12345678'],
            );
            $user->fill([
                'display_name' => $p['display_name'],
                'email' => $p['email'],
                'status' => User::STATUS_NORMAL,
                'is_admin' => false,
            ])->save();

            return $user;
        }, $people);
    }

    /** @return array{0: array<string, Group>, 1: array<string, DeviceGroup>} */
    private function seedGroups(): array
    {
        $ug = [];
        foreach ([['IT', Group::TYPE_DEFAULT], ['Support', Group::TYPE_SHARED], ['Field Techs', Group::TYPE_SHARED]] as [$name, $type]) {
            $ug[$name] = Group::updateOrCreate(['name' => $name], ['type' => $type, 'note' => $name.' team']);
        }

        $dg = [];
        foreach (['Workstations', 'Servers', 'Kiosks', 'Point of Sale'] as $i => $name) {
            $dg[$name] = DeviceGroup::updateOrCreate(['name' => $name], ['note' => $name, 'is_default' => $i === 0]);
        }

        return [$ug, $dg];
    }

    /** @return array<string, Strategy> */
    private function seedStrategies(): array
    {
        $out = [];
        $defs = [
            ['Default Policy', true, ['enable-keyboard' => 'Y', 'enable-clipboard' => 'Y', 'enable-file-transfer' => 'Y', 'enable-audio' => 'Y', 'enable-remote-restart' => 'N']],
            ['Locked-down Kiosk', false, ['enable-keyboard' => 'Y', 'enable-clipboard' => 'N', 'enable-file-transfer' => 'N', 'enable-audio' => 'N', 'hide-cm' => 'Y', 'allow-remote-config-modification' => 'N']],
            ['Servers — Restricted', false, ['enable-keyboard' => 'Y', 'enable-clipboard' => 'Y', 'enable-file-transfer' => 'Y', 'enable-remote-restart' => 'Y', 'approve-mode' => 'password']],
        ];
        foreach ($defs as $i => [$name, $isDefault, $opts]) {
            $out[$name] = Strategy::updateOrCreate(
                ['name' => $name],
                ['enabled' => true, 'is_default' => $isDefault, 'options' => $opts, 'extra' => [], 'modified_at' => time(), 'note' => $name.' — seeded demo policy'],
            );
        }

        return $out;
    }

    /**
     * @param  array<int, User>  $users
     * @param  array<string, DeviceGroup>  $deviceGroups
     * @param  array<string, Strategy>  $strategies
     * @return array<int, Device>
     */
    private function seedDevices(User $admin, array $users, array $deviceGroups, array $strategies): array
    {
        $owners = array_merge([$admin], $users);
        $catalog = [
            ['WIN-FRONTDESK', 'Windows 11 Pro', 'Workstations', true],
            ['MACBOOK-CAROL', 'macOS 14.5', 'Workstations', true],
            ['UBUNTU-BUILD01', 'Ubuntu 24.04', 'Servers', true],
            ['WIN-ACCOUNTING', 'Windows 10 Pro', 'Workstations', false],
            ['DEBIAN-DB01', 'Debian 12', 'Servers', true],
            ['KIOSK-LOBBY-1', 'Windows 11 IoT', 'Kiosks', true],
            ['KIOSK-LOBBY-2', 'Windows 11 IoT', 'Kiosks', false],
            ['POS-REGISTER-3', 'Android 13', 'Point of Sale', true],
            ['WIN-WAREHOUSE', 'Windows 11 Pro', 'Workstations', false],
            ['MAC-DESIGN-02', 'macOS 15.0', 'Workstations', true],
            ['RHEL-APP02', 'RHEL 9.4', 'Servers', true],
            ['WIN-RECEPTION', 'Windows 11 Pro', 'Workstations', false],
            ['POS-REGISTER-1', 'Android 14', 'Point of Sale', true],
            ['UBUNTU-VPN', 'Ubuntu 22.04', 'Servers', false],
        ];

        $devices = [];
        foreach ($catalog as $i => [$host, $os, $groupName, $online]) {
            $id = (string) (100000000 + $i * 1111111 % 899999999 + 12345);
            $device = Device::updateOrCreate(
                ['rustdesk_id' => $id],
                [
                    'uuid' => 'uuid-'.Str::lower(Str::random(12)),
                    'hostname' => $host,
                    'os' => $os,
                    'version' => $i % 4 === 0 ? '1.4.9' : ($i % 3 === 0 ? '1.4.8' : '1.4.9'),
                    'username' => Str::slug(explode(' ', $os)[0]).'-user',
                    'device_name' => $host,
                    'memory' => [8, 16, 32, 64][$i % 4].' GB',
                    'cpu' => ['Intel i5', 'Intel i7', 'Apple M2', 'AMD Ryzen 7', 'Xeon E-2288'][$i % 5],
                    'is_online' => $online,
                    'conns' => ($i * 7) % 40,
                    'user_id' => $owners[$i % count($owners)]->id,
                    'device_group_id' => $deviceGroups[$groupName]->id,
                    'strategy_id' => $strategies[array_keys($strategies)[$i % 3]]->id,
                    'last_online_at' => $online ? Carbon::now()->subMinutes($i * 3 + 1) : Carbon::now()->subHours($i * 5 + 2),
                    'last_online_ip' => '203.0.113.'.(10 + $i),
                    'approved' => true,
                ],
            );

            // Spread first-seen across the last two weeks so the "new devices" chart has shape.
            DB::table('devices')->where('id', $device->id)
                ->update(['created_at' => Carbon::now()->subDays(13 - ($i % 14))->setTime(9 + $i % 8, ($i * 13) % 60)]);

            $devices[] = $device;
        }

        return $devices;
    }

    /** @param array<int, Device> $devices */
    private function seedConnectionAudit(array $devices): void
    {
        if (AuditConn::count() > 20) {
            return;
        }

        $controllers = [['admin', 'Administrator'], ['alice', 'Alice Nguyen'], ['bob', 'Bob Martin'], ['carol', 'Carol Diaz']];
        // primary_auth: 1=Click 2=OTP 3=Permanent 4=SwitchSides ; two_factor: 0/1=TOTP/2=TrustedDevice
        $authMatrix = [[3, 1], [1, 0], [2, 0], [3, 2], [1, 0], [4, 0], [2, 1], [3, 0]];

        $rows = [];
        $now = Carbon::now();
        for ($d = 13; $d >= 0; $d--) {
            $perDay = 2 + (($d * 3) % 5); // 2–6 sessions/day
            for ($n = 0; $n < $perDay; $n++) {
                $device = $devices[($d + $n) % count($devices)];
                [$fromId, $fromName] = $controllers[($d + $n) % count($controllers)];
                [$primary, $two] = $authMatrix[($d * 2 + $n) % count($authMatrix)];
                $when = $now->copy()->subDays($d)->setTime(8 + ($n * 2) % 12, ($n * 17) % 60, ($d * 7) % 60);
                $session = 'sess-'.$d.'-'.$n.'-'.Str::lower(Str::random(4));
                $ref = ($primary >= 3 && ($n % 2 === 0)) ? 'ref-'.Str::lower(Str::random(10)) : null;

                $rows[] = [
                    'guid' => (string) Str::uuid(),
                    'action' => 'new',
                    'conn_id' => 1000 + $d * 10 + $n,
                    'peer_id' => $device->rustdesk_id,
                    'from_peer' => (string) (200000000 + $d * 100 + $n),
                    'from_name' => $fromName,
                    'ip' => '198.51.100.'.(20 + ($d + $n) % 200),
                    'session_id' => $session,
                    'type' => 0,
                    'primary_auth' => $primary,
                    'two_factor' => $two ?: null,
                    'conn_audit_ref' => $ref,
                    'uuid' => 'u-'.Str::lower(Str::random(8)),
                    'closed_at' => null,
                    'note' => $n === 0 && $d < 3 ? 'Assisted with printer setup.' : null,
                    'created_at' => $when,
                    'updated_at' => $when,
                ];
                // A matching close a few minutes later for the most recent day.
                if ($d <= 1) {
                    $rows[] = [
                        'guid' => null, 'action' => 'close', 'conn_id' => 1000 + $d * 10 + $n,
                        'peer_id' => $device->rustdesk_id, 'from_peer' => (string) (200000000 + $d * 100 + $n),
                        'from_name' => $fromName, 'ip' => '198.51.100.'.(20 + ($d + $n) % 200),
                        'session_id' => $session, 'type' => 0, 'primary_auth' => null, 'two_factor' => null,
                        'conn_audit_ref' => null, 'uuid' => 'u-'.Str::lower(Str::random(8)),
                        'closed_at' => $when->copy()->addMinutes(6 + $n), 'note' => null,
                        'created_at' => $when->copy()->addMinutes(6 + $n), 'updated_at' => $when->copy()->addMinutes(6 + $n),
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('audit_conns')->insert($chunk);
        }
    }

    /** @param array<int, Device> $devices */
    private function seedAlarms(array $devices): void
    {
        if (Alarm::count() > 3) {
            return;
        }

        $samples = [
            ['new_connection', 'New connection to '.$devices[0]->rustdesk_id.' from Alice Nguyen (198.51.100.24) — authenticated via Permanent password + TOTP'],
            ['Connection from a non-whitelisted IP', 'Connection from a non-whitelisted IP: 203.0.113.66'],
            ['Session-scope permission violation', 'Session-scope permission violation: terminal'],
            ['Excessive login attempts (>30)', 'Excessive login attempts (>30) from 203.0.113.90'],
            ['new_connection', 'New connection to '.$devices[2]->rustdesk_id.' from Bob Martin (198.51.100.31) — authenticated via One-time password'],
        ];
        foreach ($samples as $i => [$type, $message]) {
            $alarm = Alarm::create([
                'device_id' => $devices[$i % count($devices)]->id,
                'peer_id' => $devices[$i % count($devices)]->rustdesk_id,
                'type' => $type, 'message' => $message, 'ip' => '198.51.100.'.(24 + $i * 3), 'emailed' => $i % 2 === 0,
            ]);
            DB::table('alarms')->where('id', $alarm->id)->update(['created_at' => Carbon::now()->subHours($i * 5 + 1)]);
        }
    }

    /** @param array<int, User> $users */
    private function seedLoginLogs(User $admin, array $users): void
    {
        if (LoginLog::count() > 5) {
            return;
        }

        $all = array_merge([$admin], $users);
        $clients = [['webadmin', 'Web'], ['flutter', 'Windows'], ['flutter', 'macOS'], ['flutter', 'Android'], ['webadmin', 'Web']];
        foreach (range(0, 18) as $i) {
            $u = $all[$i % count($all)];
            [$client, $platform] = $clients[$i % count($clients)];
            $log = LoginLog::create([
                'user_id' => $u->id, 'type' => 'account', 'client' => $client,
                'device_id' => 'dev-'.Str::lower(Str::random(6)), 'uuid' => Str::lower(Str::random(10)),
                'ip' => '203.0.113.'.(30 + $i), 'platform' => $platform,
            ]);
            DB::table('login_logs')->where('id', $log->id)->update(['created_at' => Carbon::now()->subHours($i * 3 + 1)]);
        }
    }

    private function seedAddressBook(User $admin): void
    {
        $book = AddressBook::updateOrCreate(
            ['user_id' => $admin->id, 'is_personal' => true],
            [
                'name' => AddressBook::PERSONAL_NAME,
                'is_shared' => false,
                'note' => 'Primary devices',
                'max_peers' => 0,
            ],
        );
        $shared = AddressBook::updateOrCreate(
            ['user_id' => $admin->id, 'name' => 'Support — Shared'],
            ['is_shared' => true, 'note' => 'Team-visible fleet', 'max_peers' => 0],
        );

        $tagDefs = [['Servers', '#05c27b'], ['Workstations', '#3d7cf4'], ['Kiosks', '#f5a623'], ['Critical', '#ff3366']];
        $tags = [];
        foreach ($tagDefs as [$name, $color]) {
            $tags[$name] = Tag::updateOrCreate(['address_book_id' => $book->id, 'name' => $name], ['user_id' => $admin->id, 'color' => $color]);
        }

        if (AddressBookPeer::where('address_book_id', $book->id)->count() > 0) {
            return;
        }

        $peers = [
            ['300111222', 'WIN-FRONTDESK', 'Windows', ['Workstations']],
            ['300333444', 'UBUNTU-BUILD01', 'Linux', ['Servers', 'Critical']],
            ['300555666', 'MACBOOK-CAROL', 'Mac OS', ['Workstations']],
            ['300777888', 'DEBIAN-DB01', 'Linux', ['Servers', 'Critical']],
            ['300999000', 'KIOSK-LOBBY-1', 'Windows', ['Kiosks']],
            ['301222333', 'POS-REGISTER-1', 'Android', ['Kiosks']],
            ['301444555', 'RHEL-APP02', 'Linux', ['Servers']],
            ['301666777', 'WIN-RECEPTION', 'Windows', ['Workstations']],
        ];
        foreach ($peers as [$id, $host, $platform, $tagNames]) {
            AddressBookPeer::create([
                'address_book_id' => $book->id, 'user_id' => $admin->id, 'rustdesk_id' => $id,
                'hostname' => $host, 'alias' => $host, 'username' => Str::slug($host),
                'platform' => $platform, 'tags' => $tagNames,
            ]);
        }

        // A couple in the shared book too.
        foreach ([['302111000', 'UBUNTU-VPN', 'Linux'], ['302222000', 'WIN-WAREHOUSE', 'Windows']] as [$id, $host, $platform]) {
            AddressBookPeer::create([
                'address_book_id' => $shared->id, 'user_id' => $admin->id, 'rustdesk_id' => $id,
                'hostname' => $host, 'alias' => $host, 'platform' => $platform, 'tags' => [],
            ]);
        }
    }

    private function seedWebhooks(): void
    {
        $defs = [
            ['Ops Slack', Webhook::TYPE_SLACK, 'https://hooks.slack.com/services/T000/B000/xxxxxxxx', ['alarm.raised', 'connection.new'], 200, 0],
            ['On-call Telegram', Webhook::TYPE_TELEGRAM, 'https://api.telegram.org/bot123:ABC/sendMessage?chat_id=-1001', ['alarm.raised'], 200, 0],
            ['SIEM (generic)', Webhook::TYPE_GENERIC, 'https://siem.example.com/ingest/rustdesk', ['connection.new', 'connection.closed', 'device.new', 'alarm.raised'], 500, 2],
        ];
        foreach ($defs as $i => [$name, $type, $url, $events, $status, $failures]) {
            $hook = Webhook::updateOrCreate(
                ['name' => $name],
                ['type' => $type, 'url' => $url, 'secret' => $type === Webhook::TYPE_GENERIC ? 'whsec_'.Str::random(24) : null,
                    'events' => $events, 'enabled' => true, 'last_status' => $status, 'failure_count' => $failures,
                    'last_triggered_at' => Carbon::now()->subMinutes($i * 12 + 4)],
            );
            unset($hook);
        }
    }

    private function seedApiKeys(User $admin): void
    {
        $defs = [
            ['CI pipeline', ['devices.read', 'devices.write', 'audit.read'], 'rdk_ci'],
            ['Grafana metrics', ['devices.read', 'audit.read'], 'rdk_graf'],
            ['Read-only reporting', ['devices.read', 'users.read', 'ab.read'], 'rdk_ro'],
        ];
        foreach ($defs as $i => [$name, $scopes, $prefix]) {
            ApiKey::updateOrCreate(
                ['name' => $name],
                ['user_id' => $admin->id, 'credential_version' => max(1, (int) $admin->credential_version),
                    'token_hash' => hash('sha256', Str::random(40)), 'prefix' => $prefix,
                    'scopes' => $scopes, 'last_used_at' => Carbon::now()->subHours($i * 6 + 1), 'last_used_ip' => '203.0.113.'.(40 + $i)],
            );
        }
    }
}
