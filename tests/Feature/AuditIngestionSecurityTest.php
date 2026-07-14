<?php

namespace Tests\Feature;

use App\Models\Alarm;
use App\Models\AuditConn;
use App\Models\AuditFile;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuditIngestionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_current_client_payloads_from_an_approved_matching_device_are_accepted(): void
    {
        Device::create(['rustdesk_id' => 'host-1', 'uuid' => 'uuid-1']);

        // RustDesk uses a random u64 session id. Send raw JSON so this verifies that values above
        // PHP_INT_MAX are preserved exactly rather than rounded by the framework JSON decoder.
        $conn = $this->call(
            'POST',
            '/api/audit/conn',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.10'],
            '{"id":"host-1","uuid":"uuid-1","conn_id":7,'
                .'"session_id":18446744073709551615,"peer":["viewer-1","Alice"],"type":0}'
        );
        $this->assertSame(200, $conn->getStatusCode());
        $this->assertSame('{}', $conn->getContent());

        $this->postJson('/api/audit/file', [
            'id' => 'host-1',
            'uuid' => 'uuid-1',
            'peer_id' => 'viewer-1',
            'type' => 0,
            'path' => '/tmp/report.txt',
            'is_file' => true,
            'info' => '{"num":1}',
        ])->assertOk();

        $this->postJson('/api/audit/alarm', [
            'id' => 'host-1',
            'uuid' => 'uuid-1',
            'typ' => 9,
            'info' => ['scope' => 'terminal'],
        ])->assertOk();

        $this->assertDatabaseHas('audit_conns', [
            'peer_id' => 'host-1',
            'session_id' => '18446744073709551615',
            'action' => AuditConn::ACTION_NEW,
        ]);
        $this->assertDatabaseHas('audit_files', [
            'from_peer' => 'host-1',
            'peer_id' => 'viewer-1',
            'uuid' => 'uuid-1',
        ]);
        $this->assertDatabaseHas('alarms', [
            'peer_id' => 'host-1',
            'type' => 'Session-scope permission violation',
        ]);
    }

    public function test_unknown_mismatched_unapproved_and_missing_device_credentials_silently_no_op(): void
    {
        Device::create(['rustdesk_id' => 'approved', 'uuid' => 'correct']);
        Device::create(['rustdesk_id' => 'pending', 'uuid' => 'pending-uuid', 'approved' => false]);

        $responses = [
            $this->postJson('/api/audit/conn', [
                'id' => 'approved', 'uuid' => 'wrong', 'action' => 'new',
                'conn_id' => 1, 'session_id' => 1,
            ]),
            $this->postJson('/api/audit/file', [
                'id' => 'approved', 'uuid' => 'wrong', 'info' => 'x',
            ]),
            $this->postJson('/api/audit/alarm', [
                'id' => 'approved', 'typ' => 1, 'info' => 'missing uuid',
            ]),
            $this->postJson('/api/audit/alarm', [
                'id' => 'unknown', 'uuid' => 'anything', 'typ' => 1, 'info' => 'unknown',
            ]),
            $this->postJson('/api/audit/alarm', [
                'id' => 'pending', 'uuid' => 'pending-uuid', 'typ' => 1, 'info' => 'pending',
            ]),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            $this->assertSame('{}', $response->getContent());
        }

        $this->assertSame(0, AuditConn::count());
        $this->assertSame(0, AuditFile::count());
        $this->assertSame(0, Alarm::count());
    }

    public function test_oversized_and_malformed_fields_silently_no_op_without_side_effects(): void
    {
        Device::create(['rustdesk_id' => 'host-limits', 'uuid' => 'uuid-limits']);

        $bodyTooLarge = $this->postJson('/api/audit/conn', [
            'id' => 'host-limits',
            'uuid' => 'uuid-limits',
            'action' => 'new',
            'conn_id' => 1,
            'session_id' => 1,
            'padding' => str_repeat('x', 17000),
        ]);
        $alarmInfoTooLarge = $this->postJson('/api/audit/alarm', [
            'id' => 'host-limits',
            'uuid' => 'uuid-limits',
            'typ' => 1,
            'info' => str_repeat('x', 8193),
        ]);
        $fileInfoTooLarge = $this->postJson('/api/audit/file', [
            'id' => 'host-limits',
            'uuid' => 'uuid-limits',
            'info' => str_repeat('x', 60001),
        ]);
        $invalidPeer = $this->postJson('/api/audit/conn', [
            'id' => 'host-limits',
            'uuid' => 'uuid-limits',
            'action' => 'new',
            'conn_id' => 2,
            'session_id' => 2,
            'peer' => ['viewer', str_repeat('n', 256)],
        ]);

        foreach ([$bodyTooLarge, $alarmInfoTooLarge, $fileInfoTooLarge, $invalidPeer] as $response) {
            $response->assertOk();
            $this->assertSame('{}', $response->getContent());
        }

        $this->assertSame(0, AuditConn::count());
        $this->assertSame(0, AuditFile::count());
        $this->assertSame(0, Alarm::count());
    }

    public function test_per_device_alarm_rate_limit_acknowledges_but_drops_excess_events(): void
    {
        config()->set('rustdesk.audit.rate_limits.per_device.alarm', 1);
        Device::create(['rustdesk_id' => 'rate-device', 'uuid' => 'rate-uuid']);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = $this->postJson('/api/audit/alarm', [
                'id' => 'rate-device',
                'uuid' => 'rate-uuid',
                'typ' => 1,
                'info' => 'attempt '.$attempt,
            ]);

            $response->assertOk();
            $this->assertSame('{}', $response->getContent());
        }

        $this->assertSame(1, Alarm::count());
    }

    public function test_aggregate_source_ip_rate_limit_spans_multiple_devices(): void
    {
        config()->set('rustdesk.audit.rate_limits.valid_per_ip', 1);
        Device::create(['rustdesk_id' => 'rate-a', 'uuid' => 'uuid-a']);
        Device::create(['rustdesk_id' => 'rate-b', 'uuid' => 'uuid-b']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
            ->postJson('/api/audit/alarm', [
                'id' => 'rate-a', 'uuid' => 'uuid-a', 'typ' => 1, 'info' => 'first',
            ])->assertOk();
        $second = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
            ->postJson('/api/audit/alarm', [
                'id' => 'rate-b', 'uuid' => 'uuid-b', 'typ' => 1, 'info' => 'second',
            ]);

        $second->assertOk();
        $this->assertSame('{}', $second->getContent());
        $this->assertSame(1, Alarm::count());
        $this->assertDatabaseHas('alarms', ['peer_id' => 'rate-a']);
        $this->assertDatabaseMissing('alarms', ['peer_id' => 'rate-b']);
    }

    public function test_legacy_note_requires_uuid_and_is_scoped_to_the_matching_peer(): void
    {
        Device::create(['rustdesk_id' => 'note-a', 'uuid' => 'uuid-a']);
        Device::create(['rustdesk_id' => 'note-b', 'uuid' => 'uuid-b']);
        $rowA = AuditConn::create([
            'action' => AuditConn::ACTION_NEW, 'conn_id' => 1, 'peer_id' => 'note-a',
            'session_id' => '99', 'type' => 0,
        ]);
        $rowB = AuditConn::create([
            'action' => AuditConn::ACTION_NEW, 'conn_id' => 2, 'peer_id' => 'note-b',
            'session_id' => '99', 'type' => 0,
        ]);

        $this->postJson('/api/audit/conn', [
            'id' => 'note-a', 'session_id' => 99, 'note' => 'unauthenticated',
        ])->assertOk();
        $this->assertNull($rowA->refresh()->note);

        $this->postJson('/api/audit/conn', [
            'id' => 'note-a', 'uuid' => 'uuid-a', 'session_id' => 99, 'note' => 'scoped',
        ])->assertOk();

        $this->assertSame('scoped', $rowA->refresh()->note);
        $this->assertNull($rowB->refresh()->note);
    }

}
