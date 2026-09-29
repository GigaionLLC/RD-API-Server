<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DevicePresetWindowMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_devices_that_already_reported_close_their_preset_window(): void
    {
        $migration = require database_path(
            'migrations/2026_09_28_100001_add_presets_applied_at_to_devices_table.php'
        );

        $migration->down();

        try {
            $reported = DB::table('devices')->insertGetId([
                'rustdesk_id' => 'reported', 'uuid' => 'u1', 'os' => 'Windows', 'approved' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $pending = DB::table('devices')->insertGetId([
                'rustdesk_id' => 'not-yet-reported', 'uuid' => 'u2', 'approved' => true,
                'hostname' => 'set-by-deploy', 'created_at' => now(), 'updated_at' => now(),
            ]);

            $migration->up();

            $this->assertNotNull(DB::table('devices')->where('id', $reported)->value('presets_applied_at'));
            $this->assertNull(DB::table('devices')->where('id', $pending)->value('presets_applied_at'));
        } finally {
            DB::table('devices')->whereIn('rustdesk_id', ['reported', 'not-yet-reported'])->delete();
        }
    }
}
