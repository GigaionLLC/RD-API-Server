<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Designate one strategy as the default, applied as the lowest-priority fallback to any device
 * with no device/user/device-group assignment (so new devices get a policy instead of none).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
