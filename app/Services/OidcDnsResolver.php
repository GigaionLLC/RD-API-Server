<?php

namespace App\Services;

/**
 * Resolves every address advertised for an OIDC host, following CNAMEs explicitly so the
 * destination guard can reject a host when any answer is not globally routable.
 */
class OidcDnsResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $pending = [$host];
        $visited = [];
        $addresses = [];

        while ($pending !== [] && count($visited) < 16) {
            $candidate = strtolower((string) array_shift($pending));
            if ($candidate === '' || isset($visited[$candidate])) {
                continue;
            }

            $visited[$candidate] = true;
            $records = @dns_get_record($candidate, DNS_A | DNS_AAAA | DNS_CNAME);
            if (! is_array($records)) {
                continue;
            }

            foreach ($records as $record) {
                $type = $record['type'] ?? null;
                if ($type === 'A' && isset($record['ip'])) {
                    $addresses[] = (string) $record['ip'];
                } elseif ($type === 'AAAA' && isset($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                } elseif ($type === 'CNAME' && isset($record['target'])) {
                    $pending[] = rtrim((string) $record['target'], '.');
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
