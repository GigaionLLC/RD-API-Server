<?php

namespace Tests\Unit;

use App\Services\OidcDnsResolver;
use App\Services\WebhookDnsResolver;
use PHPUnit\Framework\TestCase;

/**
 * The destination guards can only reject an answer they were shown. dns_get_record() talks to
 * the configured resolvers directly and never enters the name service switch, so a host
 * published only through /etc/hosts would otherwise be invisible to validation while libcurl
 * would happily connect to it.
 */
class OidcDnsResolverTest extends TestCase
{
    public function test_a_host_published_only_through_the_name_service_switch_is_reported(): void
    {
        $addresses = (new OidcDnsResolver)->resolve('localhost');

        $this->assertContains('127.0.0.1', $addresses);
        foreach ($addresses as $address) {
            $this->assertNotFalse(
                filter_var($address, FILTER_VALIDATE_IP),
                $address.' is not a valid address'
            );
        }
    }

    public function test_the_webhook_resolver_sees_the_same_answers(): void
    {
        $this->assertContains('127.0.0.1', (new WebhookDnsResolver)->resolve('localhost'));
    }

    public function test_a_name_that_cannot_exist_resolves_to_nothing(): void
    {
        // .invalid is reserved by RFC 2606 and is guaranteed never to resolve.
        $this->assertSame([], (new OidcDnsResolver)->resolve('rustdesk-api-absent.invalid'));
        $this->assertSame([], (new WebhookDnsResolver)->resolve('rustdesk-api-absent.invalid'));
    }

    public function test_answers_are_deduplicated(): void
    {
        $addresses = (new OidcDnsResolver)->resolve('localhost');

        $this->assertSame(array_values(array_unique($addresses)), $addresses);
    }
}
