<?php

namespace Tests\Feature;

use App\Models\Alarm;
use App\Models\AuditConn;
use App\Models\AuditFile;
use App\Models\Device;
use App\Models\MailLog;
use App\Models\MailTemplate;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\MailService;
use App\Services\WebhookDnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * RustDesk 1.5.0 (#15759) made audit ingestion a retried, deduplicated protocol: every record
 * carries a UUID `nonce`, success is a 2xx whose body is EMPTY, and the client re-posts the same
 * record on any non-empty 2xx body, on 5xx, and on transport errors (10 s then 30 s, deadline
 * 120 s). The server side of that contract — dedupe on the nonce for five minutes, release it when
 * the write fails — is what these tests pin down.
 */
class AuditNonceDedupTest extends TestCase
{
    use RefreshDatabase;

    private const UUID_A = '0f9c6f0e-6f1e-4c1a-9d3e-2b7c5a4e1d01';

    private const UUID_B = '7b1d2f4a-8c3e-4a5b-8f6d-1e2c3b4a5d02';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::fake(['*' => Http::response('ok', 200)]);
        Mail::fake();

        // Webhook delivery resolves destinations through DNS; tests must not touch the network.
        $this->mock(WebhookDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andReturn(['8.8.8.8']);
        });
    }

    private function device(string $id, string $uuid, ?User $owner = null): Device
    {
        return Device::create([
            'rustdesk_id' => $id,
            'uuid' => $uuid,
            'user_id' => $owner?->id,
        ]);
    }

    /**
     * One webhook subscribed to both events the connection feed can raise, so a repeated side
     * effect shows up as a second delivery row.
     */
    private function webhook(): void
    {
        Webhook::create([
            'name' => 'audit', 'type' => Webhook::TYPE_GENERIC, 'url' => 'https://example.test/hook',
            'events' => ['connection.new', 'connection.closed', 'alarm.raised'], 'enabled' => true,
        ]);
    }

    /**
     * An opted-in owner with the alarm mail template, so a repeated notification is observable
     * as a second mail log row.
     */
    private function notificationMailbox(): User
    {
        $owner = User::create([
            'username' => 'audit-owner',
            'password' => 'secret12345',
            'email' => 'owner@example.test',
            'email_alarm_notification' => true,
            'status' => User::STATUS_NORMAL,
        ]);

        MailTemplate::create([
            'type' => MailService::TYPE_ALARM,
            'name' => 'Security alarm',
            'subject' => 'Alarm for {$device}',
            'contents' => '<p>{$message}</p>',
        ]);

        return $owner;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $path, array $body): TestResponse
    {
        return $this->postJson($path, $body);
    }

    private function connBody(string $id, string $uuid, string $nonce, string $session = '42'): array
    {
        return [
            'id' => $id, 'uuid' => $uuid, 'action' => 'new', 'conn_id' => 1,
            'session_id' => $session, 'peer' => ['viewer-1', 'Alice'], 'type' => 0,
            'nonce' => $nonce,
        ];
    }

    private function fileBody(string $id, string $uuid, string $nonce): array
    {
        return [
            'id' => $id, 'uuid' => $uuid, 'peer_id' => 'viewer-1', 'type' => 0,
            'path' => '/tmp/report.txt', 'is_file' => true, 'info' => '{"num":1}',
            'nonce' => $nonce,
        ];
    }

    private function alarmBody(string $id, string $uuid, string $nonce, int $typ = 1): array
    {
        return ['id' => $id, 'uuid' => $uuid, 'typ' => $typ, 'info' => 'failed login', 'nonce' => $nonce];
    }

    /**
     * The acknowledgement the 1.5.0+ client requires: 200 with nothing in the body. A `{}` would
     * make it re-post every record twice more.
     */
    public function test_all_three_feeds_acknowledge_a_stored_record_with_an_empty_body(): void
    {
        $this->device('host-1', 'uuid-1');

        $responses = [
            $this->send('/api/audit/conn', $this->connBody('host-1', 'uuid-1', self::UUID_A)),
            $this->send('/api/audit/file', $this->fileBody('host-1', 'uuid-1', self::UUID_A)),
            $this->send('/api/audit/alarm', $this->alarmBody('host-1', 'uuid-1', self::UUID_A, 9)),
        ];

        foreach ($responses as $response) {
            $response->assertStatus(200);
            $this->assertSame('', $response->getContent());
            $this->assertSame('0', (string) $response->headers->get('Content-Length'));
        }

        $this->assertSame(1, AuditConn::count());
        $this->assertSame(1, AuditFile::count());

        // The connection event also raises its own "new connection" alarm.
        $this->assertSame(2, Alarm::count());
        $this->assertDatabaseHas('alarms', ['type' => 'Session-scope permission violation']);
    }

    public function test_the_nonce_is_persisted_on_each_audit_table(): void
    {
        $this->device('host-1', 'uuid-1');

        $this->send('/api/audit/conn', $this->connBody('host-1', 'uuid-1', self::UUID_A))->assertOk();
        $this->send('/api/audit/file', $this->fileBody('host-1', 'uuid-1', self::UUID_A))->assertOk();
        $this->send('/api/audit/alarm', $this->alarmBody('host-1', 'uuid-1', self::UUID_A))->assertOk();

        $this->assertDatabaseHas('audit_conns', ['peer_id' => 'host-1', 'client_nonce' => self::UUID_A]);
        $this->assertDatabaseHas('audit_files', ['from_peer' => 'host-1', 'client_nonce' => self::UUID_A]);
        $this->assertDatabaseHas('alarms', ['peer_id' => 'host-1', 'client_nonce' => self::UUID_A]);
    }

    /**
     * The client re-posts until it sees an empty body, so the same nonce arriving again is the
     * same record: one row, one alarm, one webhook, and the same empty 200 as the first attempt.
     */
    public function test_a_retried_connection_nonce_stores_one_row_and_repeats_no_side_effects(): void
    {
        $this->webhook();
        $this->device('host-1', 'uuid-1');

        $first = $this->send('/api/audit/conn', $this->connBody('host-1', 'uuid-1', self::UUID_A));
        $retry = $this->send('/api/audit/conn', $this->connBody('host-1', 'uuid-1', self::UUID_A));
        $third = $this->send('/api/audit/conn', $this->connBody('host-1', 'uuid-1', self::UUID_A));

        foreach ([$first, $retry, $third] as $response) {
            $response->assertStatus(200);
            $this->assertSame('', $response->getContent());
        }

        $this->assertSame(1, AuditConn::count());
        $this->assertSame(1, Alarm::where('type', 'new_connection')->count());
        $this->assertSame(1, WebhookDelivery::where('event', 'connection.new')->count());
        $this->assertSame(1, WebhookDelivery::where('event', 'alarm.raised')->count());
    }

    public function test_a_retried_file_nonce_stores_one_row(): void
    {
        $this->device('host-1', 'uuid-1');

        $this->send('/api/audit/file', $this->fileBody('host-1', 'uuid-1', self::UUID_A))->assertOk();
        $this->send('/api/audit/file', $this->fileBody('host-1', 'uuid-1', self::UUID_A))->assertOk();

        $this->assertSame(1, AuditFile::count());
    }

    public function test_a_retried_alarm_nonce_stores_one_row_and_sends_no_second_mail_or_webhook(): void
    {
        $owner = $this->notificationMailbox();
        $this->webhook();
        $this->device('host-1', 'uuid-1', $owner);

        $this->send('/api/audit/alarm', $this->alarmBody('host-1', 'uuid-1', self::UUID_A))->assertOk();
        $this->send('/api/audit/alarm', $this->alarmBody('host-1', 'uuid-1', self::UUID_A))->assertOk();

        $this->assertSame(1, Alarm::count());
        $this->assertSame(1, MailLog::count());
        $this->assertSame(1, WebhookDelivery::where('event', 'alarm.raised')->count());
    }

    /**
     * Uniqueness is scoped to the reporting device, so another device reusing a value can never
     * suppress — or be suppressed by — this device's record.
     */
    public function test_the_same_nonce_from_another_device_is_stored_as_its_own_record(): void
    {
        $this->device('host-1', 'uuid-1');
        $this->device('host-2', 'uuid-2');

        $this->send('/api/audit/alarm', $this->alarmBody('host-1', 'uuid-1', self::UUID_A))->assertOk();
        $this->send('/api/audit/alarm', $this->alarmBody('host-2', 'uuid-2', self::UUID_A))->assertOk();

        $this->assertSame(2, Alarm::count());
        $this->assertDatabaseHas('alarms', ['peer_id' => 'host-1', 'client_nonce' => self::UUID_A]);
        $this->assertDatabaseHas('alarms', ['peer_id' => 'host-2', 'client_nonce' => self::UUID_A]);
    }

    /**
     * Pre-1.5.0 clients send no nonce at all: two identical events are two events.
     */
    public function test_requests_without_a_nonce_keep_their_previous_behaviour(): void
    {
        $this->device('host-1', 'uuid-1');

        $legacy = [
            'id' => 'host-1', 'uuid' => 'uuid-1', 'action' => 'new', 'conn_id' => 1,
            'session_id' => '42', 'peer' => ['viewer-1', 'Alice'], 'type' => 0,
        ];

        $this->send('/api/audit/conn', $legacy)->assertOk();
        $this->send('/api/audit/conn', $legacy)->assertOk();

        $this->assertSame(2, AuditConn::count());
        $this->assertSame(0, AuditConn::whereNotNull('client_nonce')->count());
    }

    /**
     * A write that fails must not leave the nonce busy, or the client's retry would be discarded
     * as a duplicate and the record would never be stored.
     */
    public function test_a_failed_write_returns_a_retryable_error_and_releases_the_nonce(): void
    {
        $this->device('host-1', 'uuid-1');

        // Fails after the INSERT ran, inside the transaction: the row is rolled back.
        $fail = true;
        AuditConn::created(function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('simulated storage failure');
            }
        });

        $failed = $this->send('/api/audit/conn', $this->connBody('host-1', 'uuid-1', self::UUID_A));

        $failed->assertStatus(200);
        $this->assertSame('{"error":"Audit record could not be stored"}', $failed->getContent());
        $this->assertSame(0, AuditConn::count());

        $fail = false;

        $retried = $this->send('/api/audit/conn', $this->connBody('host-1', 'uuid-1', self::UUID_A));

        $retried->assertStatus(200);
        $this->assertSame('', $retried->getContent());
        $this->assertSame(1, AuditConn::count());
    }

    public function test_a_garbage_nonce_is_dropped_without_storing_the_record(): void
    {
        $this->device('host-1', 'uuid-1');

        $rejected = $this->send('/api/audit/alarm', [
            'id' => 'host-1', 'uuid' => 'uuid-1', 'typ' => 1, 'info' => 'x',
            'nonce' => 'not a nonce with spaces',
        ]);

        $rejected->assertStatus(200);
        $this->assertSame('{}', $rejected->getContent());
        $this->assertSame(0, Alarm::count());
    }

    public function test_alarm_type_ten_is_accepted_and_labelled(): void
    {
        $this->device('host-1', 'uuid-1');

        $this->send('/api/audit/alarm', [
            'id' => 'host-1', 'uuid' => 'uuid-1', 'typ' => 10,
            'info' => '{"id":"intruder-1"}', 'nonce' => self::UUID_A,
        ])->assertOk();

        $alarm = Alarm::firstOrFail();
        $this->assertSame('ID whitelist blocked connection', $alarm->type);
        $this->assertSame('ID whitelist blocked connection: {"id":"intruder-1"}', $alarm->message);
    }

    /**
     * Since 1.5.0 the IP- and ID-whitelist alarms carry the controller-attribution token that
     * /api/audit/conn already stores.
     */
    public function test_alarm_conn_audit_ref_is_stored(): void
    {
        $this->device('host-1', 'uuid-1');

        $this->send('/api/audit/alarm', [
            'id' => 'host-1', 'uuid' => 'uuid-1', 'typ' => 10, 'info' => 'blocked',
            'conn_audit_ref' => 'ref-token-xyz', 'nonce' => self::UUID_A,
        ])->assertOk();

        $this->assertDatabaseHas('alarms', [
            'peer_id' => 'host-1',
            'conn_audit_ref' => 'ref-token-xyz',
        ]);
    }
}
