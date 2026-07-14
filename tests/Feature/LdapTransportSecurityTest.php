<?php

namespace Tests\Feature;

use App\Services\LdapService;
use Illuminate\Support\Facades\Config;
use LDAP\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LdapTransportSecurityTest extends TestCase
{
    public function test_defaults_are_disabled_but_ready_for_verified_starttls(): void
    {
        $this->assertFalse((bool) config('ldap.enabled'));
        $this->assertTrue((bool) config('ldap.use_starttls'));
        $this->assertTrue((bool) config('ldap.tls_verify'));
        $this->assertFalse((bool) config('ldap.allow_insecure'));

        $transport = app(LdapService::class)->transportConfiguration();

        $this->assertNull($transport['error']);
        $this->assertSame('ldap://ldap.example.com:389', $transport['uri']);
        $this->assertTrue($transport['start_tls']);
        $this->assertTrue($transport['encrypted']);
        $this->assertSame('StartTLS (certificate verified)', $transport['label']);
    }

    public function test_plaintext_is_refused_before_connection_or_bind(): void
    {
        $this->configureTransport(useStartTls: false);
        $service = new RecordingLdapService;

        $error = $service->testConnection();

        $this->assertIsString($error);
        $this->assertStringContainsString('plaintext LDAP is blocked', $error);
        $this->assertStringContainsString('LDAP_ALLOW_INSECURE=true', $error);
        $this->assertSame([], $service->eventNames());
    }

    public function test_explicit_override_allows_plaintext_without_pretending_it_is_secure(): void
    {
        $this->configureTransport(useStartTls: false, allowInsecure: true);
        $service = new RecordingLdapService;

        $this->assertNull($service->testConnection());

        $transport = $service->transportConfiguration();
        $this->assertSame('Plaintext LDAP (explicit insecure override)', $transport['label']);
        $this->assertFalse($transport['encrypted']);
        $this->assertSame(
            ['set_option', 'connect', 'set_option', 'set_option', 'bind', 'unbind'],
            $service->eventNames(),
        );
    }

    #[DataProvider('encryptedEndpointProvider')]
    public function test_starttls_and_ldaps_choose_the_expected_uri_without_double_upgrade(
        string $host,
        int $configuredPort,
        string $expectedUri,
        bool $expectedStartTls,
    ): void {
        $this->configureTransport(host: $host, port: $configuredPort);
        $service = new RecordingLdapService;

        $this->assertNull($service->testConnection());

        $this->assertSame($expectedUri, $service->connectedUri());
        $this->assertSame($expectedStartTls ? 1 : 0, $service->eventCount('start_tls'));
        $this->assertSame(1, $service->eventCount('bind'));
        $this->assertSame(LDAP_OPT_X_TLS_DEMAND, $service->globalCertificatePolicy());
        $this->assertLessThan(
            array_search('connect', $service->eventNames(), true),
            array_search('set_option', $service->eventNames(), true),
        );
    }

    /**
     * @return iterable<string, array{string,int,string,bool}>
     */
    public static function encryptedEndpointProvider(): iterable
    {
        yield 'bare host uses configured port and StartTLS' => [
            'directory.example.test', 1389, 'ldap://directory.example.test:1389', true,
        ];
        yield 'ldap URI uses standard port and StartTLS' => [
            'ldap://directory.example.test', 9999, 'ldap://directory.example.test:389', true,
        ];
        yield 'ldap URI embedded custom port wins' => [
            'ldap://directory.example.test:1636', 9999, 'ldap://directory.example.test:1636', true,
        ];
        yield 'ldaps URI uses standard port and skips StartTLS' => [
            'ldaps://directory.example.test', 389, 'ldaps://directory.example.test:636', false,
        ];
        yield 'ldaps URI embedded AD global catalog port wins' => [
            'ldaps://directory.example.test:3269', 389, 'ldaps://directory.example.test:3269', false,
        ];
        yield 'bracketed IPv6 remains a valid URI' => [
            'ldap://[2001:db8::1]:1389', 389, 'ldap://[2001:db8::1]:1389', true,
        ];
    }

    public function test_certificate_verification_cannot_be_disabled_silently(): void
    {
        $this->configureTransport(tlsVerify: false);
        $blocked = new RecordingLdapService;

        $error = $blocked->testConnection();
        $this->assertIsString($error);
        $this->assertStringContainsString('certificate verification is disabled', $error);
        $this->assertSame([], $blocked->eventNames());

        Config::set('ldap.allow_insecure', true);
        $overridden = new RecordingLdapService;
        $this->assertNull($overridden->testConnection());
        $this->assertSame(LDAP_OPT_X_TLS_NEVER, $overridden->globalCertificatePolicy());
        $this->assertSame(1, $overridden->eventCount('start_tls'));
    }

    public function test_failed_starttls_never_falls_through_to_a_bind(): void
    {
        $this->configureTransport();
        $service = new RecordingLdapService;
        $service->startTlsSucceeds = false;

        $error = $service->testConnection();

        $this->assertIsString($error);
        $this->assertSame(1, $service->eventCount('start_tls'));
        $this->assertSame(0, $service->eventCount('bind'));
        $this->assertSame(1, $service->eventCount('unbind'));
    }

    #[DataProvider('invalidEndpointProvider')]
    public function test_invalid_endpoint_configuration_is_rejected_before_connection(
        string $host,
        mixed $port,
        string $expectedError,
    ): void {
        $this->configureTransport(host: $host);
        Config::set('ldap.port', $port);
        $service = new RecordingLdapService;

        $error = $service->testConnection();

        $this->assertIsString($error);
        $this->assertStringContainsString($expectedError, $error);
        $this->assertSame([], $service->eventNames());
    }

    /**
     * @return iterable<string, array{string,mixed,string}>
     */
    public static function invalidEndpointProvider(): iterable
    {
        yield 'empty host' => ['', 389, 'must not be empty'];
        yield 'unsupported scheme' => ['https://directory.example.test', 389, 'only ldap:// or ldaps://'];
        yield 'URI credentials' => ['ldap://user:secret@directory.example.test', 389, 'must not contain credentials'];
        yield 'URI path' => ['ldap://directory.example.test/users', 389, 'must not contain credentials'];
        yield 'multiple endpoints' => ['directory-one.test directory-two.test', 389, 'without whitespace'];
        yield 'host with inline port but no scheme' => ['directory.example.test:389', 389, 'valid hostname'];
        yield 'zero port' => ['directory.example.test', 0, 'must be an integer'];
        yield 'out of range port' => ['directory.example.test', 65536, 'must be an integer'];
        yield 'non numeric port' => ['directory.example.test', 'not-a-port', 'must be an integer'];
    }

    private function configureTransport(
        string $host = 'directory.example.test',
        int $port = 389,
        bool $useStartTls = true,
        bool $tlsVerify = true,
        bool $allowInsecure = false,
    ): void {
        Config::set([
            'ldap.enabled' => true,
            'ldap.host' => $host,
            'ldap.port' => $port,
            'ldap.use_starttls' => $useStartTls,
            'ldap.tls_verify' => $tlsVerify,
            'ldap.allow_insecure' => $allowInsecure,
            'ldap.bind_dn' => 'cn=service,dc=example,dc=test',
            'ldap.bind_password' => 'test-only-password',
        ]);
    }
}

final class RecordingLdapService extends LdapService
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public bool $startTlsSucceeds = true;

    /** @return list<string> */
    public function eventNames(): array
    {
        return array_map(
            static fn (array $event): string => (string) $event['event'],
            $this->events,
        );
    }

    public function eventCount(string $name): int
    {
        return count(array_filter(
            $this->events,
            static fn (array $event): bool => $event['event'] === $name,
        ));
    }

    public function connectedUri(): string
    {
        foreach ($this->events as $event) {
            if ($event['event'] === 'connect') {
                return (string) $event['uri'];
            }
        }

        throw new RuntimeException('No LDAP connection was initialized.');
    }

    public function globalCertificatePolicy(): int
    {
        foreach ($this->events as $event) {
            if ($event['event'] === 'set_option'
                && $event['scope'] === 'global'
                && $event['option'] === LDAP_OPT_X_TLS_REQUIRE_CERT) {
                return (int) $event['value'];
            }
        }

        throw new RuntimeException('No global certificate policy was applied.');
    }

    protected function initializeLdapConnection(string $uri): Connection
    {
        $this->events[] = ['event' => 'connect', 'uri' => $uri];
        $connection = ldap_connect('ldap://127.0.0.1:389');
        if ($connection === false) {
            throw new RuntimeException('Could not create the test LDAP handle.');
        }

        return $connection;
    }

    protected function setLdapOption(
        ?Connection $connection,
        int $option,
        array|string|int|bool $value,
    ): bool {
        $this->events[] = [
            'event' => 'set_option',
            'scope' => $connection === null ? 'global' : 'connection',
            'option' => $option,
            'value' => $value,
        ];

        return true;
    }

    protected function startLdapTls(Connection $connection): bool
    {
        $this->events[] = ['event' => 'start_tls'];

        return $this->startTlsSucceeds;
    }

    protected function bindLdap(
        Connection $connection,
        ?string $dn = null,
        ?string $password = null,
    ): bool {
        $this->events[] = [
            'event' => 'bind',
            'has_dn' => $dn !== null && $dn !== '',
            'has_password' => $password !== null && $password !== '',
        ];

        return true;
    }

    protected function unbindLdap(Connection $connection): bool
    {
        $this->events[] = ['event' => 'unbind'];

        return true;
    }

    protected function ldapError(Connection $connection): string
    {
        return 'simulated LDAP transport failure';
    }
}
