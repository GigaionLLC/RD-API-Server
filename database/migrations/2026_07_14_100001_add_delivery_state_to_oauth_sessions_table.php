<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_sessions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('delivery_count')->default(0)->after('auth_body');
            $table->timestamp('delivered_at')->nullable()->after('delivery_count');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_sessions', function (Blueprint $table): void {
            $table->dropColumn(['delivery_count', 'delivered_at']);
        });
    }
};
