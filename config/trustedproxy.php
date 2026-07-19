<?php

$proxies = array_values(array_filter(array_map(
    static function (string $proxy): ?string {
        $proxy = trim($proxy);
        if ($proxy === '*') {
            return $proxy;
        }

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
    // A wildcard deliberately trusts the immediate caller and therefore overrides narrower
    // entries. Exact proxy IP addresses/CIDRs remain the recommended production boundary.
    'proxies' => in_array('*', $proxies, true) ? '*' : $proxies,
];
