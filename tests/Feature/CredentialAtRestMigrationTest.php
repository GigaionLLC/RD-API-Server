<?php

namespace Tests\Feature;

use App\Models\AddressBookPeer;
use App\Models\OauthSession;
use App\Models\User;
use App\Services\OauthService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Upgrading hashes already-issued credentials in place: clients and rollout scripts that hold a
 * token from before the upgrade keep working, and nobody has to sign in again.
 */
class CredentialAtRestMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_tokens_still_work_after_the_hashing_migration(): void
    {
        $migration = require database_path('migrations/2026_09_28_100003_hash_client_and_deploy_tokens.php');
        $migration->down();

        $user = User::create(['username' => 'legacy', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL, 'is_admin' => true]);
        DB::table('auth_tokens')->insert([
            'user_id' => $user->id, 'credential_version' => 1, 'token' => 'pre-upgrade-bearer-token',
            'status' => 1, 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deploy_tokens')->insert([
            'user_id' => $user->id, 'credential_version' => 1, 'token' => 'pre-upgrade-deploy-token',
            'name' => 'Legacy rollout', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $body = '{"access_token":"issued-before-upgrade","type":"access_token","user":{"name":"legacy","info":{}}}';
        DB::table('oauth_sessions')->insert([
            'code' => 'pollcode-issued-before-upgrade-1', 'op' => 'kc', 'rustdesk_id' => 'dev', 'uuid' => 'uuid',
            'auth_body' => $body, 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame(hash('sha256', 'pre-upgrade-bearer-token'), DB::table('auth_tokens')->value('token_hash'));
        $this->assertSame(hash('sha256', 'pre-upgrade-deploy-token'), DB::table('deploy_tokens')->value('token_hash'));
        $this->assertNotSame($body, DB::table('oauth_sessions')->value('auth_body'));
        $this->assertSame(OauthSession::keyFor('pollcode-issued-before-upgrade-1'), DB::table('oauth_sessions')->value('code'));

        // The client that logged in before the upgrade is still signed in.
        $this->withHeader('Authorization', 'Bearer pre-upgrade-bearer-token')
            ->postJson('/api/currentUser')->assertOk()->assertJsonPath('name', 'legacy');

        // The rollout script's deploy token still enrolls devices.
        $this->withHeader('Authorization', 'Bearer pre-upgrade-deploy-token')
            ->postJson('/api/devices/cli', ['id' => 'after-upgrade', 'uuid' => 'after-uuid'])
            ->assertOk()->assertContent('');

        // A device login that was mid-poll during the upgrade still receives its exact AuthBody.
        $this->assertSame($body, app(OauthService::class)->pollResult('pollcode-issued-before-upgrade-1', 'dev', 'uuid'));
    }

    public function test_peer_secret_migration_encrypts_idempotently_and_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_09_28_100004_encrypt_address_book_peer_secrets.php');
        $migration->down();

        $id = DB::table('address_book_peers')->insertGetId([
            'address_book_id' => 1, 'rustdesk_id' => 'legacy-peer', 'password' => 'legacy-pass',
            'hash' => 'legacy-hash', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $emptyId = DB::table('address_book_peers')->insertGetId([
            'address_book_id' => 1, 'rustdesk_id' => 'empty-peer', 'password' => '', 'hash' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();
        $first = DB::table('address_book_peers')->where('id', $id)->first();
        $this->assertSame('legacy-hash', Crypt::decryptString($first->hash));
        $this->assertSame('legacy-pass', AddressBookPeer::findOrFail($id)->password);
        $this->assertSame('', AddressBookPeer::findOrFail($emptyId)->password);
        $this->assertNull(AddressBookPeer::findOrFail($emptyId)->hash);

        $migration->up();
        $this->assertSame($first->hash, DB::table('address_book_peers')->where('id', $id)->value('hash'));

        $migration->down();
        $this->assertSame('legacy-hash', DB::table('address_book_peers')->where('id', $id)->value('hash'));
        $this->assertSame('legacy-pass', DB::table('address_book_peers')->where('id', $id)->value('password'));

        $migration->up();
    }
}
