<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One device group may be flagged as the default: newly-registered (and currently ungrouped)
 * devices are placed into it, so a group-level strategy applies to them automatically instead
 * of them landing in "None" with no policy. See SystemController / DeploymentService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_groups', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->index()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('device_groups', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
