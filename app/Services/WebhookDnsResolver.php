<?php

namespace App\Services;

/**
 * Resolves every address advertised for a webhook host, following CNAMEs explicitly so the
 * caller can reject a destination when any answer is not globally routable.
 *
 * Answers from DNS and from the name service switch are unioned, never substituted. Widening
 * the set can only turn an accepted destination into a rejected one, because the guard
 * requires every answer to pass, so an additional source cannot weaken the boundary.
 */
class WebhookDnsResolver
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

            // dns_get_record() issues one query per record type and discards everything it has
            // already collected as soon as any single type query hard-fails. Internal and
            // split-horizon resolvers routinely SERVFAIL on CNAME or AAAA while answering A
            // correctly, so each type is asked for separately and a failing type is skipped.
            foreach ([DNS_A, DNS_AAAA, DNS_CNAME] as $type) {
                $records = @dns_get_record($candidate, $type);
                if (! is_array($records)) {
                    continue;
                }

                foreach ($records as $record) {
                    $recordType = $record['type'] ?? null;
                    if ($recordType === 'A' && isset($record['ip'])) {
                        $addresses[] = (string) $record['ip'];
                    } elseif ($recordType === 'AAAA' && isset($record['ipv6'])) {
                        $addresses[] = (string) $record['ipv6'];
                    } elseif ($recordType === 'CNAME' && isset($record['target'])) {
                        $pending[] = rtrim((string) $record['target'], '.');
                    }
                }
            }

            // dns_get_record() talks to the configured resolvers directly and never enters the
            // name service switch, so a host published only through /etc/hosts -- Compose
            // extra_hosts, Kubernetes hostAliases -- is invisible to it even though libcurl
            // would honour it. Those addresses join the set the guard has to approve.
            //
            // PHP offers no IPv6 form of this lookup, so it contributes IPv4 answers only. A
            // host published solely as an IPv6 hosts entry still needs a real AAAA record.
            $switchAddresses = @gethostbynamel($candidate);
            if (is_array($switchAddresses)) {
                foreach ($switchAddresses as $switchAddress) {
                    if (filter_var($switchAddress, FILTER_VALIDATE_IP) !== false) {
                        $addresses[] = (string) $switchAddress;
                    }
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
