<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\AddressBookPeer;
use App\Models\DeployToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bearer, deploy and address-book peer secrets are not stored in plaintext, while every client
 * response stays byte-for-byte what it was.
 */
class CredentialAtRestTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $username = 'alice'): string
    {
        User::create(['username' => $username, 'password' => 'secret12345', 'status' => User::STATUS_NORMAL]);

        $token = $this->postJson('/api/login', [
            'username' => $username, 'password' => 'secret12345', 'id' => 'dev', 'uuid' => 'uuid',
        ])->assertOk()->json('access_token');
        $this->assertIsString($token);

        return $token;
    }

    public function test_client_bearer_tokens_are_stored_as_digests_and_still_authenticate(): void
    {
        $token = $this->login();

        $stored = DB::table('auth_tokens')->first();
        $this->assertSame(hash('sha256', $token), $stored->token_hash);
        $this->assertFalse(property_exists($stored, 'token'));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/currentUser')->assertOk()->assertJsonPath('name', 'alice');
        $this->withHeader('Authorization', 'Bearer '.$stored->token_hash)
            ->postJson('/api/currentUser')->assertUnauthorized();
    }

    public function test_deploy_tokens_are_stored_as_digests_and_shown_once(): void
    {
        $admin = User::create(['username' => 'root', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL, 'is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.deploy-tokens.store'), ['name' => 'Rollout']);
        $plain = $response->getSession()->get('new_token');
        $this->assertIsString($plain);

        $row = DB::table('deploy_tokens')->first();
        $this->assertSame(hash('sha256', $plain), $row->token_hash);
        $this->assertFalse(property_exists($row, 'token'));

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/devices/cli', ['id' => 'cli-1', 'uuid' => 'cli-uuid'])
            ->assertOk()->assertContent('');
    }

    public function test_new_deploy_tokens_get_a_default_expiry_unless_disabled(): void
    {
        $admin = User::create(['username' => 'root', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL, 'is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.deploy-tokens.store'), ['name' => 'Default ttl']);
        $expires = DeployToken::where('name', 'Default ttl')->firstOrFail()->expires_at;
        $this->assertNotNull($expires);
        $this->assertTrue($expires->between(now()->addDays(364), now()->addDays(366)));

        $this->actingAs($admin)->post(route('admin.deploy-tokens.store'), ['name' => 'Explicit', 'expires_at' => now()->addDays(10)->toDateString()]);
        $this->assertTrue(DeployToken::where('name', 'Explicit')->firstOrFail()->expires_at->lt(now()->addDays(11)));

        config()->set('rustdesk.devices.deploy_token_ttl_days', 0);
        $this->actingAs($admin)->post(route('admin.deploy-tokens.store'), ['name' => 'Forever']);
        $this->assertNull(DeployToken::where('name', 'Forever')->firstOrFail()->expires_at);
    }

    public function test_peer_password_and_hash_are_encrypted_but_returned_unchanged(): void
    {
        $token = $this->login();
        $owner = User::where('username', 'alice')->firstOrFail();
        $book = AddressBook::create(['user_id' => $owner->id, 'name' => 'Team', 'is_shared' => true]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/ab/peer/add/'.$book->id, ['id' => '4242', 'hash' => 'pw-equivalent-hash', 'password' => 'peer-pass'])
            ->assertOk();

        $raw = DB::table('address_book_peers')->where('rustdesk_id', '4242')->first();
        $this->assertNotSame('pw-equivalent-hash', $raw->hash);
        $this->assertNotSame('peer-pass', $raw->password);
        $this->assertSame('pw-equivalent-hash', Crypt::decryptString($raw->hash));
        $this->assertSame('peer-pass', AddressBookPeer::where('rustdesk_id', '4242')->firstOrFail()->password);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/ab/peers?ab='.$book->id)
            ->assertOk()
            ->assertJsonPath('data.0.hash', 'pw-equivalent-hash');
    }
}
