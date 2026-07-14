<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table): void {
            $table->string('source_ip', 45)->nullable()->after('conn_id');
        });
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table): void {
            $table->dropColumn('source_ip');
        });
    }
};
