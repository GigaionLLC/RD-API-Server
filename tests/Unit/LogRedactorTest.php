<?php

namespace Tests\Unit;

use App\Services\LogRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Redaction for a report that will be pasted into a public issue tracker.
 *
 * A miss here publishes an operator's infrastructure or a credential to everyone, so these
 * tests are written as "this specific thing must not survive" rather than as a happy path.
 * The counterpart matters too: a report stripped of everything is one nobody can reason
 * from, so the tests also pin what deliberately stays legible.
 */
class LogRedactorTest extends TestCase
{
    public function test_secrets_are_removed_by_name_not_by_shape(): void
    {
        // A password of "hunter2" has no pattern to recognise. Matching the name is the
        // only thing that catches it.
        $out = (new LogRedactor)->redact(implode("\n", [
            'DB_PASSWORD=hunter2',
            'ADMIN_PASS: correct-horse',
            '"api_key": "abc123"',
            'RUSTDESK_PUBLIC_KEY=LJVxr28H2+i15TGQcbawyOnD4AG1TO+aTZZpfvoWJMo=',
            'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
        ]));

        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertStringNotContainsString('correct-horse', $out);
        $this->assertStringNotContainsString('abc123', $out);
        $this->assertStringNotContainsString('LJVxr28H2', $out);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiIs', $out);

        // The names stay, because "DB_PASSWORD is set" is the useful half.
        $this->assertStringContainsString('DB_PASSWORD', $out);
    }

    public function test_key_material_does_not_survive_on_its_own(): void
    {
        // Keys appear in logs without a helpful label in front of them.
        $public = base64_encode(random_bytes(32));
        $private = base64_encode(random_bytes(64));

        $out = (new LogRedactor)->redact("handshake failed for {$public} / {$private}");

        $this->assertStringNotContainsString($public, $out);
        $this->assertStringNotContainsString($private, $out);
        $this->assertMatchesRegularExpression('/<key-\d+>/', $out);
    }

    public function test_addresses_hosts_and_urls_are_replaced(): void
    {
        $out = (new LogRedactor)->redact(implode("\n", [
            'connecting to 203.0.113.44:21116',
            'relay at rustdesk1.customer-domain.com',
            'https://api-rustdesk1.customer-domain.com/admin/devices?q=x',
            'peer 2001:db8:85a3::8a2e:370:7334 unreachable',
            'nic 3c:22:fb:1a:2b:3c',
        ]));

        foreach (['203.0.113.44', 'customer-domain.com', '2001:db8', '3c:22:fb'] as $secret) {
            $this->assertStringNotContainsString($secret, $out, "{$secret} must not survive");
        }
        $this->assertStringNotContainsString('/admin/devices?q=x', $out, 'a URL path can identify a deployment');
    }

    public function test_the_same_value_always_gets_the_same_placeholder(): void
    {
        // This is what keeps a report readable: two lines about one host must still look
        // like two lines about one host.
        $redactor = new LogRedactor;
        $out = $redactor->redact(implode("\n", [
            'A 10.10.0.14 -> id.internal.example',
            'B 10.10.0.14 -> id.internal.example',
            'C 10.10.3.17 -> id.internal.example',
        ]));

        $lines = explode("\n", $out);
        $this->assertSame(
            substr($lines[0], 2),
            substr($lines[1], 2),
            'identical input lines must redact identically'
        );
        $this->assertNotSame(substr($lines[1], 2), substr($lines[2], 2), 'different hosts stay distinguishable');
    }

    public function test_peer_ids_are_pseudonymised_but_timestamps_stay(): void
    {
        // The id identifies a machine; the timestamp is how a reader orders the events.
        $out = (new LogRedactor)->redact('2026-08-17 session for 345890346 at 1755400000');

        $this->assertStringNotContainsString('345890346', $out);
        $this->assertStringContainsString('1755400000', $out, 'a unix timestamp is not identifying');
        $this->assertStringContainsString('2026-08-17', $out, 'the date must stay readable');
    }

    public function test_what_a_report_needs_stays_legible(): void
    {
        // A report with nothing left in it is one nobody can act on.
        $out = (new LogRedactor)->redact(implode("\n", [
            'RD-API-Server 1.5.0 on PHP 8.5.8, MariaDB 11.8.8',
            'production.ERROR: Undefined array key "displays" in machine.js:214',
            'connecting to 127.0.0.1:3306',
            'GET /admin/remote 200',
        ]));

        foreach (['1.5.0', 'PHP 8.5.8', 'MariaDB 11.8.8', 'Undefined array key', 'machine.js',
            '127.0.0.1', '/admin/remote', '200',
            // A log channel and its level look exactly like a hostname. Losing them would
            // strip the severity from every line, which is most of why a log is readable.
            'production.ERROR'] as $keep) {
            $this->assertStringContainsString($keep, $out, "{$keep} is diagnostic, not identifying");
        }
    }

    public function test_a_harmless_pair_cannot_swallow_a_secret_after_it(): void
    {
        // Found by reading a real report, not by a test. A single pattern for both `:` and
        // `=` matched the leftmost pair — `production.DEBUG:` — whose value consumed the
        // whole of `DB_PASSWORD=hunter2`, so the password was never examined and shipped.
        $out = (new LogRedactor)->redact(
            '[2026-08-17 03:10:14] production.DEBUG: DB_PASSWORD=hunter2 ADMIN_PASS=letmein'
        );

        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertStringNotContainsString('letmein', $out);
        $this->assertStringContainsString('production.DEBUG', $out);
    }

    public function test_a_kernel_release_is_not_mistaken_for_an_address(): void
    {
        // `6.6.87.2` is a perfectly valid IPv4 address, so the platform line was being
        // replaced with a placeholder — losing the one thing it existed to report.
        $out = (new LogRedactor)->redact('OS: Linux 6.6.87.2-microsoft-standard-WSL2');

        $this->assertStringContainsString('6.6.87.2-microsoft', $out);
        // And a real address in the same shape of line still goes.
        $this->assertStringNotContainsString('203.0.113.9',
            (new LogRedactor)->redact('OS: Linux 6.6.87.2-microsoft on 203.0.113.9'));
    }

    public function test_the_summary_counts_without_naming(): void
    {
        $redactor = new LogRedactor;
        $redactor->redact('10.0.0.1 and 10.0.0.2 and 10.0.0.1 at secret.example.org');
        $summary = $redactor->summary();

        $this->assertSame(2, $summary['ip'], 'repeats count once');
        $this->assertSame(1, $summary['host']);
        $this->assertStringNotContainsString('10.0.0.1', json_encode($summary));
    }

    public function test_redaction_is_idempotent(): void
    {
        // The preview is redacted text; redacting it again must not mangle the placeholders
        // or start replacing them with each other.
        $redactor = new LogRedactor;
        $once = $redactor->redact('host id.example.org at 10.0.0.5');
        $twice = (new LogRedactor)->redact($once);

        $this->assertSame($once, $twice);
    }
}
