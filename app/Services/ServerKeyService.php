<?php

namespace App\Services;

/**
 * The RustDesk server key, reduced to the half that may leave this machine.
 *
 * This application never signs anything. It holds this key for one purpose: handing it to
 * clients and to the browser viewer, which use it to *verify* that the server signing a
 * handshake is the one they were configured for. Verification needs the public half and
 * nothing else, so the private half has no use here — there is no code path that wants it.
 *
 * Which matters, because it is easy to configure by accident. An Ed25519 signing key is 64
 * bytes — a random seed followed by a copy of the 32-byte public key — and RustDesk writes
 * the two halves next to each other as `id_ed25519` and `id_ed25519.pub`. Point
 * `RUSTDESK_KEY` or `RUSTDESK_KEY_FILE` at the first and this server will publish, to every
 * client that asks for a configuration, the key whose entire purpose is to prove the
 * server's identity. Anyone holding it can impersonate the ID server to every client on the
 * deployment.
 *
 * So this class takes whatever is configured and yields only the public half, deriving it
 * from a private key rather than refusing: an operator who made this mistake gets a working
 * deployment and a loud diagnostic, instead of a broken one and a puzzle. `hbbs` would
 * refuse the private key anyway, so passing it through helps nobody.
 */
class ServerKeyService
{
    private const PUBLIC_BYTES = 32;

    /** Seed ‖ public key. The tail is what we keep. */
    private const PRIVATE_BYTES = 64;

    /**
     * The key to distribute: always public, always base64, empty when unusable.
     *
     * Empty is a meaningful answer. A deployment with no key is unauthenticated but works;
     * one that distributes a malformed key is broken in a way nobody can diagnose from the
     * client end, so a value that is neither half of a pair is dropped rather than passed on.
     */
    public function publicKey(): string
    {
        $raw = $this->decoded();

        if ($raw === null) {
            return '';
        }

        return base64_encode(
            strlen($raw) === self::PRIVATE_BYTES ? substr($raw, self::PUBLIC_BYTES) : $raw
        );
    }

    /** Whether the configured value is the private half. Always worth reporting. */
    public function isPrivate(): bool
    {
        $raw = $this->decoded();

        return $raw !== null && strlen($raw) === self::PRIVATE_BYTES;
    }

    /** Whether something is configured but is neither half of a key pair. */
    public function isMalformed(): bool
    {
        return $this->configured() !== '' && $this->decoded() === null;
    }

    public function isConfigured(): bool
    {
        return $this->configured() !== '';
    }

    /**
     * The raw bytes of a usable key, or null when nothing usable is configured.
     *
     * Strict base64: a key with a stray character is a typo, and decoding it loosely would
     * yield bytes that are not the operator's key at all.
     */
    private function decoded(): ?string
    {
        $value = $this->configured();
        if ($value === '') {
            return null;
        }

        $raw = base64_decode($value, true);
        if ($raw === false) {
            return null;
        }

        return in_array(strlen($raw), [self::PUBLIC_BYTES, self::PRIVATE_BYTES], true) ? $raw : null;
    }

    /** The configured value, inline first, then the key file. */
    private function configured(): string
    {
        $inline = trim((string) config('rustdesk.key', ''));
        if ($inline !== '') {
            return $inline;
        }

        $path = trim((string) config('rustdesk.key_file', ''));

        return $path !== '' && is_file($path) ? trim((string) @file_get_contents($path)) : '';
    }
}
