<?php

namespace Tests\Feature;

use App\Models\AuditConn;
use App\Models\Device;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CSV exports of the device inventory and audit logs, honouring the active search filter.
 */
class ExportCsvTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'admin'.uniqid(), 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
    }

    public function test_devices_export_streams_csv(): void
    {
        Device::create(['rustdesk_id' => '123456789', 'uuid' => 'u1', 'alias' => 'Front desk', 'is_online' => true]);

        $res = $this->actingAs($this->admin())->get(route('admin.devices.export'));

        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));

        $csv = $res->streamedContent();
        $this->assertStringContainsString('id,alias,hostname', $csv); // header row
        $this->assertStringContainsString('123456789', $csv);
        $this->assertStringContainsString('Front desk', $csv);
    }

    public function test_devices_export_honours_the_search_filter(): void
    {
        Device::create(['rustdesk_id' => 'keep-1', 'uuid' => 'a', 'alias' => 'Reception']);
        Device::create(['rustdesk_id' => 'other-2', 'uuid' => 'b', 'alias' => 'Server']);

        $csv = $this->actingAs($this->admin())
            ->get(route('admin.devices.export', ['q' => 'keep']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('keep-1', $csv);
        $this->assertStringNotContainsString('other-2', $csv);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spreadsheetFormulaCells(): array
    {
        return [
            'equals hidden behind BOM and whitespace' => ["\xEF\xBB\xBF \t=HYPERLINK(\"https://example.test\",\"open\")"],
            'plus hidden behind a tab' => ["\t+SUM(1,1)"],
            'minus hidden behind spaces' => ['  -2+3'],
            'at sign hidden behind a control' => ["\x0B@SUM(1,1)"],
        ];
    }

    #[DataProvider('spreadsheetFormulaCells')]
    public function test_exports_neutralize_spreadsheet_formula_cells(string $formula): void
    {
        Device::create(['rustdesk_id' => 'formula-device', 'uuid' => 'formula-uuid', 'alias' => $formula]);

        $csv = $this->actingAs($this->admin())
            ->get(route('admin.devices.export'))
            ->assertOk()
            ->streamedContent();

        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $this->assertIsArray($lines);
        $row = str_getcsv($lines[1]);

        $this->assertSame("'".$formula, $row[1]);
    }

    public function test_connection_audit_export_streams_csv(): void
    {
        AuditConn::create([
            'action' => 'new', 'conn_id' => 1, 'peer_id' => '987654321',
            'from_peer' => '111', 'from_name' => 'Alice', 'ip' => '198.51.100.5',
        ]);

        $csv = $this->actingAs($this->admin())
            ->get(route('admin.audit.connections.export'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('peer_id', $csv);
        $this->assertStringContainsString('987654321', $csv);
        $this->assertStringContainsString('Alice', $csv);
    }

    public function test_login_audit_export_includes_username(): void
    {
        $u = User::create(['username' => 'bob', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL]);
        LoginLog::create(['user_id' => $u->id, 'type' => 'account', 'client' => 'desktop', 'device_id' => 'd9', 'ip' => '203.0.113.7', 'platform' => 'Windows']);

        $csv = $this->actingAs($this->admin())
            ->get(route('admin.audit.logins.export'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('bob', $csv);
        $this->assertStringContainsString('203.0.113.7', $csv);
    }
}
