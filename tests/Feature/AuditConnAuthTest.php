<?php

namespace Tests\Feature;

use App\Models\Alarm;
use App\Models\AuditConn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RustDesk 1.4.9 enriched the connection-audit payload (POST /api/audit/conn) with three new
 * optional keys — primary_auth and two_factor (PR #15456, how the session authenticated) and
 * conn_audit_ref (PR #15407, an opaque controller-user attribution token). These guard that
 * the server ingests, persists, and surfaces them without breaking pre-1.4.9 clients, and that
 * the wire keys are read verbatim.
 */
class AuditConnAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_connection_persists_auth_details(): void
    {
        $this->postJson('/api/audit/conn', [
            'id' => 'dev-a', 'action' => 'new', 'conn_id' => 5, 'peer_id' => 'dev-a',
            'peer' => ['ctrl-1', 'Alice'], 'ip' => '203.0.113.9', 'session_id' => 'sess-a',
            'type' => 0, 'primary_auth' => 3, 'two_factor' => 1, 'conn_audit_ref' => 'ref-token-xyz',
        ])->assertOk();

        $this->assertDatabaseHas('audit_conns', [
            'peer_id' => 'dev-a', 'action' => 'new',
            'primary_auth' => 3, 'two_factor' => 1, 'conn_audit_ref' => 'ref-token-xyz',
        ]);

        $row = AuditConn::where('peer_id', 'dev-a')->first();
        $this->assertSame('Permanent password + TOTP', $row->authSummary());
    }

    public function test_missing_auth_fields_stay_null_for_backward_compatibility(): void
    {
        // A pre-1.4.9 client (or a plain click-through) omits the new keys entirely.
        $this->postJson('/api/audit/conn', [
            'id' => 'dev-b', 'action' => 'new', 'conn_id' => 1, 'peer_id' => 'dev-b',
            'peer' => ['ctrl-2', 'Bob'], 'ip' => '198.51.100.4', 'session_id' => 'sess-b', 'type' => 0,
        ])->assertOk();

        $row = AuditConn::where('peer_id', 'dev-b')->firstOrFail();
        $this->assertNull($row->primary_auth);
        $this->assertNull($row->two_factor);
        $this->assertNull($row->conn_audit_ref);
        $this->assertSame('', $row->authSummary());
    }

    public function test_close_event_carries_no_auth_details(): void
    {
        $this->postJson('/api/audit/conn', [
            'id' => 'dev-c', 'action' => 'close', 'conn_id' => 2, 'peer_id' => 'dev-c',
            'session_id' => 'sess-c', 'type' => 0,
        ])->assertOk();

        $row = AuditConn::where('peer_id', 'dev-c')->firstOrFail();
        $this->assertNull($row->primary_auth);
        $this->assertNull($row->conn_audit_ref);
        $this->assertNotNull($row->closed_at);
    }

    public function test_new_connection_alarm_message_includes_auth_summary(): void
    {
        $this->postJson('/api/audit/conn', [
            'id' => 'dev-d', 'action' => 'new', 'conn_id' => 3, 'peer_id' => 'dev-d',
            'peer' => ['ctrl-4', 'Dana'], 'ip' => '203.0.113.11', 'session_id' => 'sess-d',
            'type' => 0, 'primary_auth' => 2, 'two_factor' => 2,
        ])->assertOk();

        $alarm = Alarm::where('peer_id', 'dev-d')->firstOrFail();
        $this->assertStringContainsString('authenticated via One-time password + Trusted device', $alarm->message);
    }

    public function test_session_scope_violation_alarm_type_9_is_labelled(): void
    {
        // PR #15469: a new AlarmAuditType (9) posted to /api/audit/alarm must be accepted and
        // rendered with a human label rather than the generic fallback.
        $this->postJson('/api/audit/alarm', [
            'id' => 'dev-e', 'uuid' => 'ue', 'typ' => 9, 'info' => 'terminal',
        ])->assertOk();

        $this->assertDatabaseHas('alarms', [
            'peer_id' => 'dev-e',
            'type' => 'Session-scope permission violation',
        ]);
    }

    public function test_admin_connection_view_renders_auth_details(): void
    {
        $admin = User::create([
            'username' => 'audit-viewer', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        AuditConn::create([
            'action' => AuditConn::ACTION_NEW, 'conn_id' => 1, 'peer_id' => 'dev-f',
            'from_peer' => 'ctrl-6', 'from_name' => 'Frank', 'ip' => '203.0.113.20',
            'session_id' => 'sess-f', 'type' => 0, 'primary_auth' => 1, 'two_factor' => 1,
            'conn_audit_ref' => 'attr-token-9',
        ]);

        $this->actingAs($admin)->get('/admin/audit/connections')
            ->assertOk()
            ->assertSee('Click-approved')
            ->assertSee('TOTP')
            ->assertSee('attr-token-9'); // controller-attribution indicator tooltip
    }

    public function test_csv_export_includes_auth_columns(): void
    {
        $admin = User::create([
            'username' => 'audit-exporter', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        AuditConn::create([
            'action' => AuditConn::ACTION_NEW, 'conn_id' => 1, 'peer_id' => 'dev-g',
            'session_id' => 'sess-g', 'type' => 0, 'primary_auth' => 4, 'two_factor' => 2,
            'conn_audit_ref' => 'attr-token-g',
        ]);

        $res = $this->actingAs($admin)->get('/admin/audit/connections/export');
        $res->assertOk();
        $body = $res->streamedContent();

        $this->assertStringContainsString('primary_auth,two_factor,conn_audit_ref', $body);
        $this->assertStringContainsString('Switch sides', $body);
        $this->assertStringContainsString('Trusted device', $body);
        $this->assertStringContainsString('attr-token-g', $body);
    }
}
