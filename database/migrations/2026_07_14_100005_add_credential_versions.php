<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('credential_version')->default(1);
        });

        foreach (['auth_tokens', 'api_keys', 'deploy_tokens', 'verify_codes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                // Existing credentials remain valid for version-1 accounts during the upgrade.
                $table->unsignedBigInteger('credential_version')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (['verify_codes', 'deploy_tokens', 'api_keys', 'auth_tokens'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('credential_version');
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('credential_version');
        });
    }
};
