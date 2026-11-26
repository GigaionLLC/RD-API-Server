<?php

namespace App\Support;

/**
 * Chooses which of a destination's validated addresses is pinned into the request.
 *
 * A host that publishes both A and AAAA records was pinned by string order, and `"2606:..."`
 * sorts before `"93.184..."`, so the IPv6 answer often won. On a host or container with no IPv6
 * egress — the Docker default — the pinned request then failed outright, which took OIDC sign-in
 * or every webhook delivery down for a destination that was reachable the whole way over IPv4.
 *
 * Every address handed in here has already been validated by its guard; this only decides which
 * member of the accepted set is used, so preferring IPv4 cannot widen the boundary. When the host
 * publishes nothing but IPv6, that is what gets pinned — the choice is a preference, not a filter.
 */
final class PinnedAddress
{
    /**
     * The address to pin: the first IPv4 in sorted order, else the first IPv6 in sorted order.
     *
     * @param  non-empty-list<string>  $addresses  every resolved address, already validated
     */
    public static function preferIpv4(array $addresses): string
    {
        sort($addresses, SORT_STRING);

        foreach ($addresses as $address) {
            if (! str_contains($address, ':')) {
                return $address;
            }
        }

        return $addresses[0];
    }
}
