<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The console-operation audit log records admin mutations (POST/PUT/PATCH/DELETE) and
 * skips reads. Best-effort middleware: it must never affect the request outcome.
 */
class ConsoleAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_audit_records_admin_write(): void
    {
        $admin = User::create([
            'username' => 'auditadmin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        // A write request through the admin group: create a user.
        $this->actingAs($admin)->post('/admin/users', [
            'username' => 'newbie', 'password' => 'secret123456',
            'status' => User::STATUS_NORMAL, 'login_verify' => User::LOGIN_VERIFY_OFF,
        ])->assertRedirect();

        $this->assertDatabaseHas('console_audits', [
            'user_id' => $admin->id,
            'method' => 'POST',
            'route_name' => 'admin.users.store',
            'path' => 'admin/users',
        ]);
    }

    public function test_console_audit_ignores_reads(): void
    {
        $admin = User::create([
            'username' => 'readadmin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($admin)->get('/admin/users')->assertOk();

        $this->assertDatabaseCount('console_audits', 0);
    }
}
