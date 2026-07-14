<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Validates and pins outbound webhook destinations to globally routable IP addresses.
 *
 * The DNS result is consumed by CURLOPT_RESOLVE for the request that immediately follows,
 * closing the validation/request DNS-rebinding window. Redirects and inherited proxies are
 * disabled separately by WebhookService.
 */
class WebhookDestinationGuard
{
    /** @var list<string> */
    private const DENIED_IPV4_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /** @var list<string> */
    private const DENIED_IPV6_RANGES = [
        '::/96',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '100:0:0:1::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
        '5f00::/16',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    public function __construct(private readonly WebhookDnsResolver $resolver) {}

    /**
     * Resolve a destination immediately before sending and return the connection pin.
     *
     * @return array{host: string, port: int, ip: string, is_ip_literal: bool}
     */
    public function resolve(string $url): array
    {
        $parts = @parse_url($url);
        if (! is_array($parts)) {
            throw new InvalidArgumentException('Webhook URL is malformed.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Webhook URL must use HTTP or HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Webhook URL must not contain user credentials.');
        }

        $rawHost = (string) ($parts['host'] ?? '');
        $hasOpeningBracket = str_starts_with($rawHost, '[');
        $hasClosingBracket = str_ends_with($rawHost, ']');
        if ($hasOpeningBracket !== $hasClosingBracket
            || (! $hasOpeningBracket && (str_contains($rawHost, '[') || str_contains($rawHost, ']')))) {
            throw new InvalidArgumentException('Webhook URL contains an invalid host.');
        }

        $host = $hasOpeningBracket ? substr($rawHost, 1, -1) : $rawHost;
        if ($hasOpeningBracket && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException('Webhook URL contains an invalid host.');
        }

        if ($host === '' || $host !== rtrim($host, '.') || preg_match('/[^\x21-\x7e]/', $host) === 1 || str_contains($host, '%')) {
            throw new InvalidArgumentException('Webhook URL contains an invalid host.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $allowedPorts = array_values(array_filter(
            array_map('intval', (array) config('rustdesk.webhooks.allowed_ports', [80, 443])),
            static fn (int $allowed): bool => $allowed >= 1 && $allowed <= 65535
        ));
        if (! in_array($port, $allowedPorts, true)) {
            throw new InvalidArgumentException('Webhook URL uses a port that is not allowed.');
        }

        $isIpLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (! $isIpLiteral) {
            if ($this->resemblesNonCanonicalIpv4($host)
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new InvalidArgumentException('Webhook URL contains an invalid host.');
            }

            $addresses = $this->resolver->resolve(strtolower($host));
        } else {
            $addresses = [$host];
        }

        if ($addresses === []) {
            throw new InvalidArgumentException('Webhook host could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                throw new InvalidArgumentException('Webhook host resolves to a non-public network.');
            }
        }

        sort($addresses, SORT_STRING);

        return [
            'host' => strtolower($host),
            'port' => $port,
            'ip' => $addresses[0],
            'is_ip_literal' => $isIpLiteral,
        ];
    }

    /**
     * Build request options that prevent a second DNS lookup or environment-proxy bypass.
     *
     * @param  array{host: string, port: int, ip: string, is_ip_literal: bool}  $destination
     * @return array<string, mixed>
     */
    public function requestOptions(array $destination): array
    {
        foreach (['CURLOPT_RESOLVE', 'CURLOPT_FRESH_CONNECT', 'CURLOPT_FORBID_REUSE', 'CURLOPT_PROXY', 'CURLOPT_FOLLOWLOCATION'] as $constant) {
            if (! defined($constant)) {
                throw new RuntimeException('Secure webhook transport requires the PHP cURL extension.');
            }
        }

        $curl = [
            constant('CURLOPT_FRESH_CONNECT') => true,
            constant('CURLOPT_FORBID_REUSE') => true,
            // Set these on libcurl itself as well as through the HTTP client so environment
            // proxy variables and handler defaults cannot bypass the validated connection.
            constant('CURLOPT_PROXY') => '',
            constant('CURLOPT_FOLLOWLOCATION') => false,
        ];

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $curl[constant('CURLOPT_PROTOCOLS_STR')] = 'http,https';
        } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $curl[constant('CURLOPT_PROTOCOLS')] = constant('CURLPROTO_HTTP') | constant('CURLPROTO_HTTPS');
        } else {
            throw new RuntimeException('Secure webhook transport cannot restrict libcurl protocols.');
        }

        if (! $destination['is_ip_literal']) {
            $ip = str_contains($destination['ip'], ':') ? '['.$destination['ip'].']' : $destination['ip'];
            $curl[constant('CURLOPT_RESOLVE')] = [
                $destination['host'].':'.$destination['port'].':'.$ip,
            ];
        }

        return [
            'proxy' => '',
            'curl' => $curl,
        ];
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $isIpv6 = str_contains($ip, ':');
        if ($isIpv6 && ! $this->isInRange($ip, '2000::/3')) {
            return false;
        }

        $ranges = $isIpv6 ? self::DENIED_IPV6_RANGES : self::DENIED_IPV4_RANGES;

        foreach ($ranges as $range) {
            if ($this->isInRange($ip, $range)) {
                return false;
            }
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function resemblesNonCanonicalIpv4(string $host): bool
    {
        $number = '(?:0x[0-9a-f]+|0[0-7]+|[0-9]+)';

        return preg_match('/^'.$number.'(?:\.'.$number.'){0,3}$/i', $host) === 1;
    }

    private function isInRange(string $ip, string $range): bool
    {
        [$network, $prefixLength] = explode('/', $range, 2);
        $packedIp = inet_pton($ip);
        $packedNetwork = inet_pton($network);
        if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }

        $prefix = (int) $prefixLength;
        $wholeBytes = intdiv($prefix, 8);
        if (substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }
}
