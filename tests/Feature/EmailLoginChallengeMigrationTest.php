<?php

namespace Tests\Feature;

use App\Models\VerifyCode;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmailLoginChallengeMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migration_retires_legacy_plaintext_codes(): void
    {
        $migration = require database_path(
            'migrations/2026_07_14_100003_harden_email_login_challenges.php'
        );

        try {
            $migration->down();

            DB::table('verify_codes')->insert([
                'user_id' => 1,
                'type' => VerifyCode::TYPE_EMAIL,
                'uuid' => 'legacy-uuid',
                'code' => '123456',
                'rustdesk_id' => 'legacy-device',
                'status' => VerifyCode::STATUS_ACTIVE,
                'expires_at' => now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $migration->up();

            $legacy = DB::table('verify_codes')->where('uuid', 'legacy-uuid')->first();
            $this->assertNotNull($legacy);
            $this->assertNull($legacy->code);
            $this->assertNull($legacy->challenge_hash);
            $this->assertSame(VerifyCode::STATUS_INACTIVE, $legacy->status);

            DB::table('verify_codes')->where('uuid', 'legacy-uuid')->update([
                'challenge_hash' => str_repeat('a', 64),
                'code' => Hash::make('654321'),
                'status' => VerifyCode::STATUS_ACTIVE,
            ]);
            $migration->down();

            $rolledBack = DB::table('verify_codes')->where('uuid', 'legacy-uuid')->first();
            $this->assertNotNull($rolledBack);
            $this->assertNull($rolledBack->code);
            $this->assertSame(VerifyCode::STATUS_INACTIVE, $rolledBack->status);
        } finally {
            DB::table('verify_codes')->where('uuid', 'legacy-uuid')->delete();

            if (! Schema::hasColumn('verify_codes', 'challenge_hash')) {
                $migration->up();
            }
        }
    }
}
