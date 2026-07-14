<?php

namespace Tests\Feature;

use App\Models\AuditConn;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuditSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_alarm_creates_an_alarm(): void
    {
        Device::create(['rustdesk_id' => 'dev-9', 'uuid' => 'u9']);

        $this->postJson('/api/audit/alarm', [
            'id' => 'dev-9', 'uuid' => 'u9', 'typ' => 0, 'info' => '203.0.113.5',
        ])->assertOk();

        $this->assertDatabaseHas('alarms', [
            'peer_id' => 'dev-9',
            'type' => 'Connection from a non-whitelisted IP',
        ]);
    }

    public function test_operator_session_note_attaches_to_open_session(): void
    {
        Device::create(['rustdesk_id' => 'dev-1', 'uuid' => 'u1']);

        AuditConn::create([
            'action' => AuditConn::ACTION_NEW, 'conn_id' => 1, 'peer_id' => 'dev-1',
            'session_id' => 'sess-1', 'type' => 0,
        ]);

        $this->postJson('/api/audit/conn', [
            'id' => 'dev-1', 'uuid' => 'u1', 'session_id' => 'sess-1',
            'note' => 'investigating',
        ])->assertOk();

        $this->assertDatabaseHas('audit_conns', [
            'session_id' => 'sess-1', 'note' => 'investigating',
        ]);
    }

    public function test_heartbeat_delivers_queued_disconnect(): void
    {
        Cache::put('rd:disconnect:dev-2', [7], now()->addMinutes(5));

        $this->postJson('/api/heartbeat', [
            'id' => 'dev-2', 'uuid' => 'u2', 'modified_at' => 0,
        ])->assertOk()->assertJson(['disconnect' => [7]]);

        // Delivered once, then cleared.
        $this->assertNull(Cache::get('rd:disconnect:dev-2'));
    }
}
