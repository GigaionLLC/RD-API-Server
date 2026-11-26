<?php

namespace Tests\Unit;

use App\Services\OidcDestinationGuard;
use App\Services\OidcDnsResolver;
use App\Services\WebhookDestinationGuard;
use App\Services\WebhookDnsResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Which of a destination's accepted addresses is pinned into the request.
 *
 * A host that publishes both an A and an AAAA record used to be pinned by string order, and
 * `"2606:..."` sorts before `"93.184..."` — so the IPv6 answer won. On a host or container with
 * no IPv6 egress, which is the Docker default, the pinned request then failed outright: OIDC
 * sign-in or every webhook delivery broke for a destination that was reachable over IPv4 all
 * along. Validation is untouched: every resolved address must still be public, and one bad
 * answer in either family still rejects the destination. Only the choice among the accepted
 * addresses changed.
 *
 * The resolvers are stubbed through the same protected seams tests/Unit/DnsResolverSearchDomainTest
 * uses, so nothing here touches a real resolver.
 */
class DestinationGuardPinTest extends TestCase
{
    private const PUBLIC_IPV4 = '93.184.216.34';

    private const OTHER_PUBLIC_IPV4 = '1.1.1.1';

    private const PUBLIC_IPV6 = '2606:2800:220:1:248:1893:25c8:1946';

    private const OTHER_PUBLIC_IPV6 = '2a00:1450:4001:80f::200e';

    private const HOST = 'edge.example.com';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function guards(): iterable
    {
        yield 'OIDC guard' => ['oidc', 'https://'.self::HOST.'/oidc'];
        yield 'webhook guard' => ['webhook', 'https://'.self::HOST.'/notify'];
    }

    #[DataProvider('guards')]
    public function test_a_host_with_both_families_pins_the_ipv4_address(string $kind, string $url): void
    {
        $guard = $this->guard($kind, [
            self::HOST.'.' => [
                DNS_A => [self::a(self::PUBLIC_IPV4)],
                DNS_AAAA => [self::aaaa(self::PUBLIC_IPV6)],
            ],
        ]);

        $destination = $guard->resolve($url);

        $this->assertSame(self::PUBLIC_IPV4, $destination['ip']);
        $this->assertSame(
            [self::HOST.':443:'.self::PUBLIC_IPV4],
            $guard->requestOptions($destination)['curl'][constant('CURLOPT_RESOLVE')]
        );
    }

    #[DataProvider('guards')]
    public function test_the_first_ipv4_in_sorted_order_wins_when_several_are_public(string $kind, string $url): void
    {
        $guard = $this->guard($kind, [
            self::HOST.'.' => [
                DNS_A => [self::a(self::PUBLIC_IPV4), self::a(self::OTHER_PUBLIC_IPV4)],
                DNS_AAAA => [self::aaaa(self::PUBLIC_IPV6)],
            ],
        ]);

        // Deterministic, and independent of the order the resolver answered in.
        $this->assertSame(self::OTHER_PUBLIC_IPV4, $guard->resolve($url)['ip']);
    }

    #[DataProvider('guards')]
    public function test_an_ipv6_only_host_pins_ipv6(string $kind, string $url): void
    {
        $guard = $this->guard($kind, [
            self::HOST.'.' => [
                DNS_AAAA => [self::aaaa(self::PUBLIC_IPV6), self::aaaa(self::OTHER_PUBLIC_IPV6)],
            ],
        ]);

        $destination = $guard->resolve($url);

        $this->assertSame(self::PUBLIC_IPV6, $destination['ip']);
        $this->assertSame(
            [self::HOST.':443:['.self::PUBLIC_IPV6.']'],
            $guard->requestOptions($destination)['curl'][constant('CURLOPT_RESOLVE')]
        );
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function rejectedAnswers(): iterable
    {
        foreach (self::guards() as $label => [$kind, $url]) {
            // A private answer in either family still rejects the destination: preferring IPv4
            // must not turn "some answer is internal" into "the good answer was pinned".
            yield $label.' · private IPv4 beside a public IPv6' => [$kind, $url, [
                DNS_A => [self::a('10.20.30.40')],
                DNS_AAAA => [self::aaaa(self::PUBLIC_IPV6)],
            ]];

            yield $label.' · public IPv4 beside a private IPv6' => [$kind, $url, [
                DNS_A => [self::a(self::PUBLIC_IPV4)],
                DNS_AAAA => [self::aaaa('fd00::1')],
            ]];

            yield $label.' · loopback IPv4 beside a public IPv6' => [$kind, $url, [
                DNS_A => [self::a('127.0.0.1')],
                DNS_AAAA => [self::aaaa(self::PUBLIC_IPV6)],
            ]];

            yield $label.' · link-local IPv6 beside a public IPv4' => [$kind, $url, [
                DNS_A => [self::a(self::PUBLIC_IPV4)],
                DNS_AAAA => [self::aaaa('fe80::1')],
            ]];
        }
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    #[DataProvider('rejectedAnswers')]
    public function test_a_non_public_answer_in_either_family_still_rejects_the_destination(
        string $kind,
        string $url,
        array $answers,
    ): void {
        $guard = $this->guard($kind, [self::HOST.'.' => $answers]);

        $this->expectException(InvalidArgumentException::class);

        $guard->resolve($url);
    }

    /**
     * @param  array<string, array<int, list<array<string, mixed>>>>  $dns
     */
    private function guard(string $kind, array $dns): OidcDestinationGuard|WebhookDestinationGuard
    {
        if ($kind === 'oidc') {
            $resolver = new PinStubOidcDnsResolver;
            $resolver->dns = $dns;

            return new OidcDestinationGuard($resolver);
        }

        $resolver = new PinStubWebhookDnsResolver;
        $resolver->dns = $dns;

        return new WebhookDestinationGuard($resolver);
    }

    /**
     * @return array<string, mixed>
     */
    private static function a(string $ip): array
    {
        return ['host' => self::HOST, 'class' => 'IN', 'type' => 'A', 'ip' => $ip];
    }

    /**
     * @return array<string, mixed>
     */
    private static function aaaa(string $ipv6): array
    {
        return ['host' => self::HOST, 'class' => 'IN', 'type' => 'AAAA', 'ipv6' => $ipv6];
    }
}

/**
 * Serves canned answers for one resolver seam, without the name service switch.
 */
trait CannedPinAnswers
{
    /** @var array<string, array<int, list<array<string, mixed>>>> */
    public array $dns = [];

    /**
     * @return array<int, array<string, mixed>>|false
     */
    protected function records(string $name, int $type): array|false
    {
        return $this->dns[$name][$type] ?? [];
    }

    /**
     * @return list<string>|false
     */
    protected function switchAddresses(string $name): array|false
    {
        return false;
    }
}

class PinStubOidcDnsResolver extends OidcDnsResolver
{
    use CannedPinAnswers;
}

class PinStubWebhookDnsResolver extends WebhookDnsResolver
{
    use CannedPinAnswers;
}
