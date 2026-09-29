<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enrollment window for device-supplied OPTION_PRESET_* values.
 *
 * `/api/sysinfo` presets (strategy, device group, address book, displayed identity) are applied
 * only on a device's first accepted sysinfo upload, and only where an administrator has not already
 * made the assignment. `presets_applied_at` records that the window has closed.
 *
 * Existing devices that have already uploaded sysinfo (they carry client-reported inventory) had
 * their presets applied by earlier releases, so their window is closed here. Devices that were
 * enrolled but have not reported yet keep an open window and still receive their presets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->timestamp('presets_applied_at')->nullable()->after('approved');
        });

        DB::table('devices')
            ->whereNull('presets_applied_at')
            ->where(function ($query): void {
                foreach (['cpu', 'os', 'memory', 'version'] as $column) {
                    $query->orWhere(fn ($inner) => $inner->whereNotNull($column)->where($column, '!=', ''));
                }
            })
            ->update(['presets_applied_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('presets_applied_at');
        });
    }
};
