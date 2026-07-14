<?php

$proxies = array_values(array_filter(array_map(
    static function (string $proxy): ?string {
        $proxy = trim($proxy);
        if (filter_var($proxy, FILTER_VALIDATE_IP) !== false) {
            return $proxy;
        }

        [$network, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
        if ($prefix === null || filter_var($network, FILTER_VALIDATE_IP) === false
            || filter_var($prefix, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $maxPrefix = str_contains($network, ':') ? 128 : 32;
        $prefixLength = (int) $prefix;

        // /0 is equivalent to trusting every caller and defeats the purpose of this boundary.
        return $prefixLength >= 1 && $prefixLength <= $maxPrefix ? $proxy : null;
    },
    explode(',', (string) env('TRUSTED_PROXIES', '')),
), static fn (?string $proxy): bool => $proxy !== null));

return [
    // Forwarded headers affect security controls that use the client IP. Trust only the
    // explicit IP addresses or CIDR ranges of reverse proxies that sanitize those headers.
    'proxies' => $proxies,
];
