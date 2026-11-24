<?php

namespace Tests\Unit;

use App\Services\OidcDnsResolver;
use App\Services\WebhookDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DNS lookups must be fully qualified.
 *
 * An unqualified name lets the system resolver append its search domains (/etc/resolv.conf
 * `search ...`) to any query that has no answer of the requested type. When a search domain
 * publishes a wildcard record, that retry answers with real-looking A/AAAA/CNAME data for a name
 * nobody asked for, which used to be unioned into the address set the destination guard pins.
 *
 * These tests replace the network with canned answers (see the protected seams in both
 * resolvers), so they never depend on the resolver of the machine running them.
 */
class DnsResolverSearchDomainTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function resolverClasses(): iterable
    {
        yield 'OIDC resolver' => [StubOidcDnsResolver::class];
        yield 'webhook resolver' => [StubWebhookDnsResolver::class];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('resolverClasses')]
    public function test_every_lookup_uses_a_fully_qualified_name(string $class): void
    {
        $resolver = new $class;
        $resolver->dns['idp.example.com.'][DNS_A] = [
            ['host' => 'IDP.Example.COM', 'class' => 'IN', 'type' => 'A', 'ip' => '93.184.216.34'],
        ];

        $this->assertSame(['93.184.216.34'], $resolver->resolve('IDP.example.com'));

        $this->assertNotEmpty($resolver->queried);
        foreach ($resolver->queried as $name) {
            $this->assertStringEndsWith('.', $name, 'a lookup name must be fully qualified');
            $this->assertStringNotContainsString('..', $name, 'a lookup name must not repeat the dot');
        }

        $this->assertSame(['idp.example.com.'], array_values(array_unique($resolver->queried)));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('resolverClasses')]
    public function test_a_trailing_root_dot_in_the_input_is_not_doubled(string $class): void
    {
        $resolver = new $class;
        $resolver->dns['idp.example.com.'][DNS_A] = [
            ['host' => 'idp.example.com', 'class' => 'IN', 'type' => 'A', 'ip' => '93.184.216.34'],
        ];

        $this->assertSame(['93.184.216.34'], $resolver->resolve('idp.example.com.'));
        $this->assertSame(['idp.example.com.'], array_values(array_unique($resolver->queried)));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('resolverClasses')]
    public function test_an_answer_owned_by_a_search_domain_expansion_is_ignored(string $class): void
    {
        $resolver = new $class;

        // What a resolver that re-applies its search list returns for a name it cannot answer:
        // "<queried name>.<search domain>". The wildcard below answers every type.
        $resolver->dns['idp.example.com.'][DNS_A] = [
            ['host' => 'idp.example.com.corp.internal', 'class' => 'IN', 'type' => 'A', 'ip' => '15.218.42.196'],
            ['class' => 'IN', 'type' => 'A', 'ip' => '192.0.2.9'],
        ];
        $resolver->dns['idp.example.com.'][DNS_AAAA] = [
            ['host' => 'idp.example.com.corp.internal', 'class' => 'IN', 'type' => 'AAAA', 'ipv6' => '2001:db8::196'],
        ];
        $resolver->dns['idp.example.com.'][DNS_CNAME] = [
            [
                'host' => 'idp.example.com.corp.internal',
                'class' => 'IN',
                'type' => 'CNAME',
                'target' => 'hl1-dynamicdns.corp.internal',
            ],
        ];

        $this->assertSame([], $resolver->resolve('idp.example.com'));

        // The bogus target must not be queried either, or its own wildcard answers would be
        // unioned in the moment they are looked up.
        $this->assertNotContains('hl1-dynamicdns.corp.internal.', $resolver->queried);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('resolverClasses')]
    public function test_a_genuine_cname_chain_is_still_followed(string $class): void
    {
        $resolver = new $class;

        $resolver->dns['idp.example.com.'][DNS_CNAME] = [
            ['host' => 'idp.example.com', 'class' => 'IN', 'type' => 'CNAME', 'target' => 'edge.example.net.'],
        ];
        $resolver->dns['edge.example.net.'][DNS_A] = [
            ['host' => 'edge.example.net', 'class' => 'IN', 'type' => 'A', 'ip' => '93.184.216.34'],
        ];

        $this->assertSame(['93.184.216.34'], $resolver->resolve('idp.example.com'));
        $this->assertContains('edge.example.net.', $resolver->queried);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('resolverClasses')]
    public function test_answers_from_records_and_the_name_service_switch_are_unioned(string $class): void
    {
        $resolver = new $class;

        $resolver->dns['idp.example.com.'][DNS_A] = [
            ['host' => 'idp.example.com', 'class' => 'IN', 'type' => 'A', 'ip' => '8.8.8.8'],
            ['host' => 'idp.example.com', 'class' => 'IN', 'type' => 'A', 'ip' => '1.1.1.1'],
        ];
        $resolver->dns['idp.example.com.'][DNS_AAAA] = [
            ['host' => 'idp.example.com', 'class' => 'IN', 'type' => 'AAAA', 'ipv6' => '2606:4700:4700::1111'],
        ];
        $resolver->switchAnswers['idp.example.com.'] = ['9.9.9.9', '1.1.1.1'];

        $this->assertSame(
            ['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '9.9.9.9'],
            $resolver->resolve('idp.example.com')
        );
    }
}

/**
 * Serves canned answers for the protected lookup seams of the resolvers under test.
 */
trait CannedDnsAnswers
{
    /** @var array<string, array<int, list<array<string, mixed>>>> */
    public array $dns = [];

    /** @var array<string, list<string>> */
    public array $switchAnswers = [];

    /** @var list<string> */
    public array $queried = [];

    /**
     * @return array<int, array<string, mixed>>|false
     */
    protected function records(string $name, int $type): array|false
    {
        $this->queried[] = $name;

        return $this->dns[$name][$type] ?? [];
    }

    /**
     * @return list<string>|false
     */
    protected function switchAddresses(string $name): array|false
    {
        $this->queried[] = $name;

        return $this->switchAnswers[$name] ?? false;
    }
}

class StubOidcDnsResolver extends OidcDnsResolver
{
    use CannedDnsAnswers;
}

class StubWebhookDnsResolver extends WebhookDnsResolver
{
    use CannedDnsAnswers;
}
