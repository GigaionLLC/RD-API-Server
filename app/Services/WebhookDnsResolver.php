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
            $candidate = self::normalizeName((string) array_shift($pending));
            if ($candidate === '' || isset($visited[$candidate])) {
                continue;
            }

            $visited[$candidate] = true;

            // Every lookup below is issued for the fully qualified name -- the trailing root dot
            // makes it absolute, which switches off the resolver's search list. Without that dot
            // a query for a type that has no answer (CNAME usually, AAAA often) is retried as
            // "<name>.<search domain from /etc/resolv.conf>"; when that search domain publishes a
            // wildcard record, the retry returns real-looking A/AAAA/CNAME data for a name
            // nobody asked for. The wildcard target belongs to the search domain's owner, not to
            // this server's attacker, so it was never an SSRF hole -- but the address was
            // unioned into the pin set and could be pinned instead of the genuine address, which
            // made TLS fail and took webhook delivery down.
            $name = $candidate.'.';

            // dns_get_record() issues one query per record type and discards everything it has
            // already collected as soon as any single type query hard-fails. Internal and
            // split-horizon resolvers routinely SERVFAIL on CNAME or AAAA while answering A
            // correctly, so each type is asked for separately and a failing type is skipped.
            foreach ([DNS_A, DNS_AAAA, DNS_CNAME] as $type) {
                $records = $this->records($name, $type);
                if (! is_array($records)) {
                    continue;
                }

                foreach ($records as $record) {
                    // Defence in depth for a resolver that ignores, or re-adds, the search list:
                    // an answer whose own owner name is not the name we asked for describes a
                    // different host. Dropping it keeps a search-domain expansion (for example
                    // "idp.example.com.corp.internal" answering because "*.corp.internal" is a
                    // wildcard) out of the address set and off the CNAME queue, so its target is
                    // never followed either.
                    if (self::normalizeName((string) ($record['host'] ?? '')) !== $candidate) {
                        continue;
                    }

                    $recordType = $record['type'] ?? null;
                    if ($recordType === 'A' && isset($record['ip'])) {
                        $addresses[] = (string) $record['ip'];
                    } elseif ($recordType === 'AAAA' && isset($record['ipv6'])) {
                        $addresses[] = (string) $record['ipv6'];
                    } elseif ($recordType === 'CNAME' && isset($record['target'])) {
                        $pending[] = (string) $record['target'];
                    }
                }
            }

            // dns_get_record() talks to the configured resolvers directly and never enters the
            // name service switch, so a host published only through /etc/hosts -- Compose
            // extra_hosts, Kubernetes hostAliases -- is invisible to it even though libcurl
            // would honour it. Those addresses join the set the guard has to approve. The lookup
            // is qualified for the same reason as the ones above: this call does use the switch,
            // so an unqualified name is expanded through the search list before it is answered.
            //
            // PHP offers no IPv6 form of this lookup, so it contributes IPv4 answers only. A
            // host published solely as an IPv6 hosts entry still needs a real AAAA record.
            $switchAddresses = $this->switchAddresses($name);
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

    /**
     * DNS lookup seam, so tests can answer without touching a resolver.
     *
     * $name is already fully qualified (trailing root dot).
     *
     * @return array<int, array<string, mixed>>|false
     */
    protected function records(string $name, int $type): array|false
    {
        return @dns_get_record($name, $type);
    }

    /**
     * Name service switch lookup seam, so tests can answer without touching a resolver.
     *
     * $name is already fully qualified (trailing root dot).
     *
     * @return list<string>|false
     */
    protected function switchAddresses(string $name): array|false
    {
        return @gethostbynamel($name);
    }

    /**
     * Normalise a DNS name the way the wire format does: case-insensitively, without the root
     * dot. Owning names are compared in this form, so "Example.COM." and "example.com" match.
     */
    private static function normalizeName(string $name): string
    {
        return strtolower(rtrim($name, '.'));
    }
}
