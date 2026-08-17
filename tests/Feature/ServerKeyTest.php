<?php

namespace Tests\Feature;

use App\Services\ServerKeyService;
use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * The server key, and the half of it that may leave this machine.
 *
 * This application never signs anything; it holds the key only to hand out, and what
 * receives it uses it to verify. So the private half has no use here — and because the two
 * halves live side by side as `id_ed25519` and `id_ed25519.pub`, configuring the wrong one
 * is an easy mistake with a serious result: the key proving the server's identity gets
 * published to every client that asks for a configuration.
 *
 * These tests pin the guarantee that it cannot happen, however the value is configured.
 */
class ServerKeyTest extends TestCase
{
    public function test_a_private_key_never_leaves_as_one(): void
    {
        $public = random_bytes(32);
        $private = random_bytes(32).$public;   // Ed25519: seed followed by the public key

        config(['rustdesk.key' => base64_encode($private), 'rustdesk.key_file' => '']);
        $keys = new ServerKeyService;

        $this->assertSame(base64_encode($public), $keys->publicKey(),
            'the public half is derived, so the deployment works');
        $this->assertNotSame(base64_encode($private), $keys->publicKey());
        $this->assertTrue($keys->isPrivate(), 'and the mistake is still reported');
    }

    public function test_a_public_key_passes_through_unchanged(): void
    {
        $public = base64_encode(random_bytes(32));
        config(['rustdesk.key' => $public, 'rustdesk.key_file' => '']);

        $keys = new ServerKeyService;
        $this->assertSame($public, $keys->publicKey());
        $this->assertFalse($keys->isPrivate());
        $this->assertFalse($keys->isMalformed());
    }

    public function test_a_value_that_is_not_a_key_is_dropped_rather_than_distributed(): void
    {
        // Handing out a malformed key breaks every client with an error none of them can
        // diagnose. Handing out nothing is merely unauthenticated, and is reported here.
        foreach (['not base64 !!', base64_encode('short'), base64_encode(random_bytes(48))] as $bad) {
            config(['rustdesk.key' => $bad, 'rustdesk.key_file' => '']);
            $keys = new ServerKeyService;

            $this->assertSame('', $keys->publicKey(), "should not distribute: {$bad}");
            $this->assertTrue($keys->isMalformed());
        }
    }

    public function test_a_key_file_holding_the_private_key_is_handled_the_same_way(): void
    {
        // `RUSTDESK_PUBLIC_KEY_FILE` pointed at id_ed25519 rather than id_ed25519.pub is
        // the same mistake by a different route, and one an operator is more likely to make
        // when both files sit in the same directory.
        $public = random_bytes(32);
        $path = tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($path, base64_encode(random_bytes(32).$public)."\n");

        config(['rustdesk.key' => '', 'rustdesk.key_file' => $path]);
        $keys = new ServerKeyService;

        $this->assertSame(base64_encode($public), $keys->publicKey());
        $this->assertTrue($keys->isPrivate());

        unlink($path);
    }

    /**
     * Resolves `config/rustdesk.php` against a given environment.
     *
     * Through the Env repository rather than `putenv()`. An earlier version set `putenv()`
     * and `$_ENV` directly, which worked locally and failed in CI: whether `env()` sees
     * those depends on which adapters the repository was built with, and that differs
     * between environments. This is the supported way in, and behaves the same everywhere.
     *
     * @param  array<string, string>  $env
     */
    private function keyFromEnv(array $env): string
    {
        $repository = Env::getRepository();
        $names = ['RUSTDESK_PUBLIC_KEY', 'RUSTDESK_KEY'];
        $previous = [];

        foreach ($names as $name) {
            $previous[$name] = $repository->get($name);
            $repository->clear($name);
        }
        foreach ($env as $name => $value) {
            $repository->set($name, $value);
        }

        try {
            return (string) (require config_path('rustdesk.php'))['key'];
        } finally {
            foreach ($names as $name) {
                $repository->clear($name);
                if ($previous[$name] !== null) {
                    $repository->set($name, $previous[$name]);
                }
            }
        }
    }

    public function test_the_conventional_env_name_keeps_working(): void
    {
        // RUSTDESK_KEY is the spelling used across the RustDesk ecosystem. Breaking it
        // would fail silently, because an unset key disables peer verification rather than
        // raising anything.
        $public = base64_encode(random_bytes(32));

        $this->assertSame($public, $this->keyFromEnv(['RUSTDESK_KEY' => $public]),
            'RUSTDESK_KEY must still be honoured');
        $this->assertSame($public, $this->keyFromEnv(['RUSTDESK_PUBLIC_KEY' => $public]));

        $preferred = base64_encode(random_bytes(32));
        $this->assertSame($preferred, $this->keyFromEnv([
            'RUSTDESK_KEY' => $public,
            'RUSTDESK_PUBLIC_KEY' => $preferred,
        ]), 'the explicit name wins when both are set');
    }

    public function test_nothing_is_configured(): void
    {
        config(['rustdesk.key' => '', 'rustdesk.key_file' => '']);
        $keys = new ServerKeyService;

        $this->assertSame('', $keys->publicKey());
        $this->assertFalse($keys->isConfigured());
        $this->assertFalse($keys->isPrivate());
        $this->assertFalse($keys->isMalformed());
    }
}
