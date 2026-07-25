<?php

namespace App\Support;

/**
 * Parses and evaluates the operator-configured list of private networks that outbound
 * identity-provider traffic is allowed to reach.
 *
 * The destination guards fail closed on every address that is not globally routable. A
 * self-hosted identity provider on a LAN, VPN, or container network is a legitimate
 * exception, but it is a deployment trust decision: the endpoints used during a login are
 * read from the provider's own discovery document, so every address permitted here is an
 * address a compromised or spoofed provider could aim this server at.
 *
 * Two rules therefore hold regardless of what an operator writes:
 *  - Ranges that are never a valid identity provider and are the highest-value targets of a
 *    server-side request forgery (loopback, link-local, cloud instance metadata, multicast,
 *    NAT64/6to4/Teredo translation prefixes) can never be trusted.
 *  - An entry is either understood completely or discarded and reported. Nothing is guessed.
 */
final class TrustedPrivateNetworks
{
    /**
     * Never trustable, whatever the configuration says. Evaluated before the allowlist so a
     * broad entry such as fd00::/8 cannot drag in the AWS IPv6 metadata address it contains.
     *
     * @var list<string>
     */
    private const HARD_BLOCKED_RANGES = [
        // IPv4: unspecified, loopback, link-local (incl. 169.254.169.254 instance metadata),
        // IETF protocol assignments (incl. 192.0.0.192), 6to4 relay anycast, multicast, reserved.
        '0.0.0.0/8',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '192.0.0.0/24',
        '192.88.99.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        // Alibaba Cloud instance metadata, carved out of the otherwise trustable CGNAT space.
        '100.100.100.136/32',
        '100.100.100.200/32',
        // IPv6: unspecified, loopback, IPv4-compatible and IPv4-mapped forms.
        '::/96',
        '::ffff:0:0/96',
        // Translation prefixes that decode to an arbitrary IPv4 destination.
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/23',
        '2002::/16',
        // Link-local, multicast, and the AWS IPv6 instance-metadata prefix inside ULA space.
        'fe80::/10',
        'ff00::/8',
        'fd00:ec2::/32',
    ];

    /**
     * What the blanket switch expands to. Deliberately only RFC 1918 and the assigned half of
     * the IPv6 unique-local space: not loopback, not link-local, not carrier-grade NAT, and
     * not any translation prefix.
     *
     * @var list<string>
     */
    private const PRIVATE_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fd00::/8',
    ];

    /**
     * Prefix floors. A short prefix is indistinguishable from "trust the whole internet" and
     * an operator who needs one has not understood the setting.
     */
    private const MINIMUM_IPV4_PREFIX = 8;

    private const MINIMUM_IPV6_PREFIX = 7;

    /**
     * @param  list<array{network: string, prefix: int}>  $ranges  packed networks with their prefix length
     * @param  list<string>  $rejected  entries that could not be trusted, verbatim as configured
     */
    private function __construct(
        private readonly array $ranges,
        private readonly array $rejected,
    ) {}

    /**
     * Build the allowlist from a configured entry list and the blanket private-network switch.
     *
     * @param  array<int|string, mixed>  $entries
     */
    public static function fromConfiguration(array $entries, bool $allowPrivateNetworks = false): self
    {
        $ranges = [];
        $rejected = [];

        $configured = [];
        foreach ($entries as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                continue;
            }

            $configured[] = trim($entry);
        }

        if ($allowPrivateNetworks) {
            $configured = array_merge($configured, self::PRIVATE_RANGES);
        }

        foreach ($configured as $entry) {
            $range = self::parseRange($entry);
            if ($range === null) {
                $rejected[] = $entry;

                continue;
            }

            $ranges[] = $range;
        }

        return new self(
            $ranges,
            array_values(array_unique($rejected)),
        );
    }

    /**
     * Build the allowlist that applies to generic OIDC egress.
     */
    public static function forOidc(): self
    {
        return self::fromConfiguration(
            (array) config('rustdesk.oidc.allowed_networks', []),
            (bool) config('rustdesk.oidc.allow_private_networks', false),
        );
    }

    /**
     * Whether the operator trusts this address. Hard-blocked ranges answer false even when an
     * entry nominally covers them.
     */
    public function permits(string $ip): bool
    {
        $packed = self::packAddress($ip);
        if ($packed === null || $this->ranges === []) {
            return false;
        }

        if (self::isHardBlocked($ip)) {
            return false;
        }

        foreach ($this->ranges as $range) {
            if (self::matches($packed, $range['network'], $range['prefix'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Addresses that no configuration may ever reach. An address this class cannot parse is
     * treated as hard-blocked so an unexpected representation always fails closed.
     */
    public static function isHardBlocked(string $ip): bool
    {
        $packed = self::packAddress($ip);
        if ($packed === null) {
            return true;
        }

        foreach (self::hardBlockedRanges() as $range) {
            if (self::matches($packed, $range['network'], $range['prefix'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{network: string, prefix: int}>
     */
    private static function hardBlockedRanges(): array
    {
        /** @var list<array{network: string, prefix: int}>|null $parsed */
        static $parsed = null;

        if ($parsed === null) {
            $parsed = [];
            foreach (self::HARD_BLOCKED_RANGES as $range) {
                $entry = self::parseRange($range, false);
                if ($entry !== null) {
                    $parsed[] = $entry;
                }
            }
        }

        return $parsed;
    }

    public function isEmpty(): bool
    {
        return $this->ranges === [];
    }

    public function count(): int
    {
        return count($this->ranges);
    }

    /**
     * Entries that were discarded, so a failure can name them instead of silently ignoring them.
     *
     * @return list<string>
     */
    public function rejectedEntries(): array
    {
        return $this->rejected;
    }

    /**
     * Parse one entry into a packed network plus prefix length, or null when it cannot be
     * trusted exactly as written.
     *
     * @return array{network: string, prefix: int}|null
     */
    private static function parseRange(string $entry, bool $enforcePolicy = true): ?array
    {
        $entry = trim($entry);
        if ($entry === '') {
            return null;
        }

        if (str_contains($entry, '/')) {
            $parts = explode('/', $entry);
            if (count($parts) !== 2) {
                return null;
            }

            [$address, $prefix] = $parts;
            if ($prefix === '' || ! ctype_digit($prefix)) {
                return null;
            }

            $prefixLength = (int) $prefix;
        } else {
            // A bare address is a single host. It is normalized here rather than handed to the
            // matcher, where a missing prefix would otherwise read as "every address".
            $address = $entry;
            $prefixLength = str_contains($entry, ':') ? 128 : 32;
        }

        $packed = self::packAddress($address);
        if ($packed === null) {
            return null;
        }

        $isIpv6 = strlen($packed) === 16;
        $maximumPrefix = $isIpv6 ? 128 : 32;
        $minimumPrefix = $isIpv6 ? self::MINIMUM_IPV6_PREFIX : self::MINIMUM_IPV4_PREFIX;

        if ($prefixLength > $maximumPrefix || ($enforcePolicy && $prefixLength < $minimumPrefix)) {
            return null;
        }

        if (! $enforcePolicy) {
            return ['network' => $packed, 'prefix' => $prefixLength];
        }

        // Host bits set means the operator wrote a host address and a network prefix at the
        // same time. Masking it silently would trust a whole network they never named.
        if ($packed !== self::maskAddress($packed, $prefixLength)) {
            return null;
        }

        if (self::isWithinHardBlockedRange($packed, $prefixLength)) {
            return null;
        }

        return ['network' => $packed, 'prefix' => $prefixLength];
    }

    /**
     * Whether the whole configured range sits inside a hard-blocked range. Containment rather
     * than overlap, so a legitimate entry such as fd00::/8 stays usable despite covering the
     * AWS metadata prefix, which the match-time check removes instead.
     */
    private static function isWithinHardBlockedRange(string $packed, int $prefixLength): bool
    {
        foreach (self::hardBlockedRanges() as $blocked) {
            if (strlen($blocked['network']) !== strlen($packed)) {
                continue;
            }

            if ($prefixLength >= $blocked['prefix']
                && self::matches($packed, $blocked['network'], $blocked['prefix'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pack a textual address. IPv4-mapped and IPv4-compatible IPv6 forms pack to 16 bytes and
     * are caught by the hard-blocked ::/96 and ::ffff:0:0/96 entries, so an address is never
     * compared in two different representations.
     */
    private static function packAddress(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = @inet_pton($ip);
        if (! is_string($packed) || ! in_array(strlen($packed), [4, 16], true)) {
            return null;
        }

        return $packed;
    }

    private static function maskAddress(string $packed, int $prefixLength): string
    {
        $wholeBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;
        $masked = substr($packed, 0, $wholeBytes);

        if ($remainingBits !== 0 && isset($packed[$wholeBytes])) {
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            $masked .= chr(ord($packed[$wholeBytes]) & $mask);
        }

        return str_pad($masked, strlen($packed), "\0");
    }

    private static function matches(string $packedIp, string $packedNetwork, int $prefixLength): bool
    {
        if (strlen($packedIp) !== strlen($packedNetwork) || $prefixLength < 0) {
            return false;
        }

        if ($prefixLength > strlen($packedIp) * 8) {
            return false;
        }

        $wholeBytes = intdiv($prefixLength, 8);
        if (substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefixLength % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }
}
