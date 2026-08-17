<?php

namespace App\Services;

/**
 * Removes identifying and secret material from text destined for a public issue tracker.
 *
 * The output of this class is meant to be pasted into a GitHub issue, so a miss is not a
 * cosmetic defect — it publishes an operator's infrastructure, or a credential, to
 * everyone. Two decisions follow from that.
 *
 * **Consistent placeholders, not blanket removal.** The same value always becomes the same
 * token, so a reader can still follow one device or one address through a log and see that
 * two lines are about the same thing. `<ip-1>` twice is evidence; `[redacted]` twice is
 * not, and a report nobody can reason from is one nobody will ask for.
 *
 * **Ordered widest-first.** A URL contains a host, a host contains a domain; redacting the
 * domain first leaves a mangled URL that still shows the path and port. Each rule below is
 * applied in an order chosen so that the larger structure is consumed before its parts.
 *
 * What this deliberately does NOT try to be is a guarantee. It is a large reduction in what
 * leaves the building, paired with a preview the operator reads before sending — because
 * the only reliable check on "is this safe to publish" is a human who knows their own
 * deployment looking at it.
 */
class LogRedactor
{
    /** @var array<string, array<string, string>> Placeholders already issued, per kind. */
    private array $issued = [];

    /**
     * Hosts that carry no information about a deployment and are noise when redacted.
     * Leaving them legible is what makes "connects to 127.0.0.1 but not to db" readable.
     */
    private const PUBLIC_HOSTS = [
        'localhost', '127.0.0.1', '0.0.0.0', '::1',
        'github.com', 'ghcr.io', 'rustdesk.com', 'example.com', 'laravel.com', 'php.net',
    ];

    public function redact(string $text): string
    {
        // Secrets first: a key or token may appear inside a URL or a config line, and once
        // the surrounding structure is replaced the secret inside it is unreachable.
        $text = $this->secrets($text);
        $text = $this->keyMaterial($text);
        $text = $this->emails($text);
        $text = $this->urls($text);
        $text = $this->hosts($text);
        $text = $this->ipv6($text);
        $text = $this->ipv4($text);
        $text = $this->macAddresses($text);

        return $this->peerIds($text);
    }

    /**
     * A stable placeholder for one value, so the same input always yields the same token.
     */
    private function placeholder(string $kind, string $value): string
    {
        $this->issued[$kind] ??= [];

        if (! isset($this->issued[$kind][$value])) {
            $this->issued[$kind][$value] = '<'.$kind.'-'.(count($this->issued[$kind]) + 1).'>';
        }

        return $this->issued[$kind][$value];
    }

    /**
     * `KEY=value` and `"key": "value"` shapes whose name suggests a secret.
     *
     * Matching on the name rather than the value is what catches a secret that looks like
     * ordinary text — a password of `hunter2` has no pattern to recognise.
     */
    private function secrets(string $text): string
    {
        // Names are matched as whole segments rather than as substrings: `ADMIN_PASS` has
        // to be caught and `PASSED_CHECKS=3` must not be, which "contains pass" cannot
        // tell apart. See isSecretName().
        //
        // Two passes, and the order matters. `=` assignments go first, matched wherever
        // they appear. A single pattern handling both separators swallowed real secrets:
        // in `production.DEBUG: DB_PASSWORD=hunter2` the leftmost match is the harmless
        // `production.DEBUG:` pair, whose value consumed `DB_PASSWORD=hunter2` whole, so
        // the password was never examined. Found by reading a real report, not by a test.
        $text = (string) preg_replace_callback(
            '/(?<![\w=-])(["\']?)([A-Za-z][A-Za-z0-9_.-]*)\1?\s*=\s*("[^"]*"|\'[^\']*\'|[^\s,;}\]]+)/',
            fn (array $m): string => $this->isSecretName($m[2])
                ? $m[1].$m[2].$m[1].'= <redacted>'
                : $m[0],
            $text
        );

        // Then `:` pairs — JSON, YAML and headers. The value stops before an `=`, so this
        // pass cannot swallow an assignment the first pass has already dealt with.
        $text = (string) preg_replace_callback(
            '/(["\']?)\b([A-Za-z][A-Za-z0-9_.-]*)\1?\s*:\s*("[^"]*"|\'[^\']*\'|[^\s,;}\]=]+)/',
            fn (array $m): string => $this->isSecretName($m[2])
                ? $m[1].$m[2].$m[1].': <redacted>'
                : $m[0],
            $text
        );

        // Bearer tokens and basic auth in headers.
        return (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9\-\._~\+\/=]{8,}/i', '$1 <redacted>', $text);
    }

    /** Whether a configuration or header name promises a value that must not be published. */
    private function isSecretName(string $name): bool
    {
        $segments = preg_split('/[_\-\.\s]+/', strtolower($name)) ?: [];

        foreach ($segments as $segment) {
            if (in_array($segment, [
                'pass', 'passwd', 'password', 'passphrase', 'secret', 'token', 'key', 'keys',
                'credential', 'credentials', 'authorization', 'auth', 'bearer', 'cookie',
                'session', 'salt', 'signature', 'dsn', 'pat', 'apikey',
            ], true)) {
                return true;
            }
        }

        // Compound names people write without separators.
        return (bool) preg_match('/(password|passphrase|secret|apikey|api_key|privatekey|accesskey)/i', $name);
    }

    /**
     * Base64 blobs long enough to be key material.
     *
     * A RustDesk server key is 44 characters (public) or 88 (private); tokens and hashes
     * are similar. The threshold is deliberately low enough to catch both and high enough
     * not to eat ordinary words or short identifiers.
     */
    private function keyMaterial(string $text): string
    {
        return (string) preg_replace_callback(
            '/\b[A-Za-z0-9+\/]{32,}={0,2}\b/',
            function (array $m): string {
                // A long run of digits is an id or a timestamp, not a key.
                if (ctype_digit($m[0])) {
                    return $m[0];
                }

                return $this->placeholder('key', $m[0]);
            },
            $text
        );
    }

    private function emails(string $text): string
    {
        return (string) preg_replace_callback(
            '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/',
            fn (array $m): string => $this->placeholder('email', strtolower($m[0])),
            $text
        );
    }

    /** Whole URLs, so a path and port do not survive the host being replaced. */
    private function urls(string $text): string
    {
        return (string) preg_replace_callback(
            '#\b([a-z][a-z0-9+\-.]*)://([^\s"\'<>,;\)\]]+)#i',
            function (array $m): string {
                $scheme = strtolower($m[1]);
                $rest = $m[2];
                // Delimited with ~ because the class contains a #, which would otherwise
                // close a #-delimited pattern and leave "]" read as a modifier.
                $host = preg_split('~[/:?#]~', $rest)[0] ?? '';

                if ($this->isPublicHost($host)) {
                    return $m[0];
                }

                return $scheme.'://'.$this->placeholder('host', strtolower($host))
                    .(str_contains($rest, '/') ? '/<path>' : '');
            },
            $text
        );
    }

    /** Bare hostnames: `id.example.org`, `rustdesk1.internal`. */
    private function hosts(string $text): string
    {
        return (string) preg_replace_callback(
            '/\b(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,}\b/i',
            function (array $m): string {
                if ($this->isPublicHost($m[0])) {
                    return $m[0];
                }

                // Version strings and file names reach here as "words with dots".
                if (preg_match('/\.(php|js|css|json|log|yml|yaml|md|blade|sql|txt|html)$/i', $m[0])) {
                    return $m[0];
                }

                // `production.ERROR`, `local.DEBUG` — a log channel and its level look
                // exactly like a hostname. Redacting those removes the severity from every
                // line, which is most of what makes a log worth reading. No hostname is
                // written with an all-capital final label.
                $labels = explode('.', $m[0]);
                $last = end($labels);
                if ($last !== '' && $last === strtoupper($last) && ctype_alpha($last)) {
                    return $m[0];
                }

                return $this->placeholder('host', strtolower($m[0]));
            },
            $text
        );
    }

    private function ipv4(string $text): string
    {
        return (string) preg_replace_callback(
            // The trailing group captures a version-style suffix so the callback can see it.
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b(?:\/\d{1,2})?(-[A-Za-z][A-Za-z0-9]*)?/',
            function (array $m): string {
                // A kernel release is a valid IPv4 address: `6.6.87.2-microsoft-standard`
                // parses cleanly and was being replaced, taking with it the one piece of
                // platform information the line existed to carry. An address is not
                // normally followed by a hyphenated word.
                if (($m[1] ?? '') !== '') {
                    return $m[0];
                }

                $bare = explode('/', $m[0])[0];
                if (! filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $m[0];
                }
                if ($this->isPublicHost($bare)) {
                    return $m[0];
                }

                return $this->placeholder('ip', $bare);
            },
            $text
        );
    }

    private function ipv6(string $text): string
    {
        return (string) preg_replace_callback(
            '/\b(?:[0-9A-Fa-f]{0,4}:){2,7}[0-9A-Fa-f]{0,4}\b/',
            function (array $m): string {
                if (! filter_var(trim($m[0], '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    return $m[0];
                }
                if ($this->isPublicHost(trim($m[0], '[]'))) {
                    return $m[0];
                }

                return $this->placeholder('ip', strtolower($m[0]));
            },
            $text
        );
    }

    private function macAddresses(string $text): string
    {
        return (string) preg_replace_callback(
            '/\b(?:[0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}\b/',
            fn (array $m): string => $this->placeholder('mac', strtolower($m[0])),
            $text
        );
    }

    /**
     * RustDesk peer ids: nine-ish digit runs.
     *
     * These identify a specific machine, and a bug report rarely needs the real one — but
     * it does need to distinguish "the same device twice" from "two devices", which is what
     * the numbered placeholder preserves.
     */
    private function peerIds(string $text): string
    {
        return (string) preg_replace_callback(
            '/\b\d{9,12}\b/',
            function (array $m): string {
                // Unix timestamps in seconds (10 digits) and milliseconds (13) are common
                // in logs and are not identifying; leaving them legible keeps the ordering
                // of events readable.
                $n = (int) $m[0];
                if (strlen($m[0]) === 10 && $n > 1_000_000_000 && $n < 2_000_000_000) {
                    return $m[0];
                }

                return $this->placeholder('peer', $m[0]);
            },
            $text
        );
    }

    private function isPublicHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return in_array($host, self::PUBLIC_HOSTS, true);
    }

    /**
     * What was replaced, by kind — so the report can say "3 addresses, 2 hosts" without
     * naming any of them, and a reader knows redaction actually ran.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $out = [];
        foreach ($this->issued as $kind => $values) {
            $out[$kind] = count($values);
        }
        ksort($out);

        return $out;
    }
}
