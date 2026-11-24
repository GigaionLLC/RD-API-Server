<?php

namespace App\Services;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use InvalidArgumentException;

/**
 * Generates a RustDesk client "server config" so admins can pre-configure clients without
 * hand-editing each one. The same encoding is accepted by every client import path:
 *   - desktop "Import Server Config" (paste) and `rustdesk --config <string>`,
 *   - the renamed Windows installer (`rustdesk-host=…,key=….exe`),
 *   - the mobile QR scanner (payload prefixed `config=`).
 *
 * Encoding (mirrors the client's ServerConfig.encode / custom_server.rs):
 *   reverse( url-safe-base64-no-pad( json{host,relay,api,key} ) )
 * The client reverses, base64-decodes (padding-tolerant) and JSON-parses it — no signing
 * needed, since the plain-JSON branch is accepted before signature verification.
 */
class ClientConfigService
{
    public const UNLOCK_PIN_MIN_LENGTH = 4;

    public const UNLOCK_PIN_MAX_LENGTH = 128;

    /**
     * Strategy option keys that redirect a client to other infrastructure (rendezvous, relay,
     * API server, server key) or route it through a proxy with credentials. Whoever sets them
     * controls every later policy push to the device, so only unrestricted administrators may
     * add, change or remove them; delegated administrators keep whatever value is stored.
     *
     * @var list<string>
     */
    public const FULL_ADMIN_ONLY_OPTION_KEYS = [
        'custom-rendezvous-server',
        'relay-server',
        'api-server',
        'key',
        'proxy-url',
        'proxy-username',
        'proxy-password',
    ];

    /**
     * The reversed url-safe-base64 config string (no padding).
     */
    public function configString(string $host, string $relay, string $api, string $key): string
    {
        $json = json_encode([
            'host' => trim($host),
            'relay' => trim($relay),
            'api' => trim($api),
            'key' => trim($key),
        ], JSON_UNESCAPED_SLASHES);

        $b64 = rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');

        return strrev($b64);
    }

    /**
     * The payload the mobile QR scanner expects (it requires a leading "config=").
     */
    public function qrPayload(string $host, string $relay, string $api, string $key): string
    {
        return 'config='.$this->configString($host, $relay, $api, $key);
    }

    /**
     * The Windows renamed-installer filename (the client parses host=,key=,relay= from it).
     *
     * The API server is deliberately NOT emitted, even when one is configured. The client only
     * accepts an `api=` value that carries a scheme (`http://…` / `https://…` — see the client's
     * `setServerConfig`), and `:` plus `/` are invalid in a Windows file name, so the value can
     * never be part of a renamed installer. Omitting it is also what the client expects: with no
     * explicit API server it derives `http://<ID server>:21114` itself (`get_api_server_` in
     * `src/common.rs`). Deployments whose API is only reachable on https/443 therefore need the
     * config string or `--config` form instead. See installerFilenameProblems().
     *
     * The trailing comma is the terminator the client's parser documents: Windows appends
     * " (1)", " (2)", … to a duplicate download, which would otherwise corrupt the last value.
     */
    public function installerFilename(string $host, string $relay, string $api, string $key): string
    {
        $parts = ['host='.trim($host)];
        if (trim($key) !== '') {
            $parts[] = 'key='.trim($key);
        }
        if (trim($relay) !== '') {
            $parts[] = 'relay='.trim($relay);
        }

        return 'rustdesk-'.implode(',', $parts).',.exe';
    }

    /**
     * Reasons the renamed installer cannot carry this configuration, keyed by field. An empty
     * array means the generated filename is usable.
     *
     * A renamed installer is a *file name*, so every value it carries has to survive Win32 name
     * rules. A base64 server key may contain `/` (illegal) and an API URL always contains `:` and
     * `/`, which is why neither can be smuggled in silently: stripping characters would corrupt
     * the key and silently disable server authentication.
     *
     * @return array<string, string>
     */
    public function installerFilenameProblems(string $host, string $relay, string $key): array
    {
        $problems = [];
        $labels = ['host' => 'ID/rendezvous server', 'key' => 'Public key', 'relay' => 'Relay server'];

        if (trim($host) === '') {
            $problems['host'] = 'An ID/rendezvous server host is required for a renamed installer.';
        }

        foreach (['host' => $host, 'key' => $key, 'relay' => $relay] as $field => $value) {
            $value = trim($value);
            if ($value === '') {
                continue; // An empty optional value is simply omitted; the host is checked above.
            }
            if (preg_match('/[<>:"\/\\\\|?*\x00-\x1F]/', $value) === 1) {
                $problems[$field] = $labels[$field].' contains a character Windows forbids in a file name '
                    .'(< > : " / \ | ? * and control characters), so it cannot be part of a renamed installer.';
            }
        }

        return $problems;
    }

    /**
     * Inline SVG QR code for arbitrary data (no GD required).
     */
    public function qrSvg(string $data): string
    {
        return (new SvgWriter)->write(new QrCode($data))->getString();
    }

    /**
     * Build a per-OS deploy-time install script that applies a strategy's options via the
     * client CLI (`rustdesk --option <key> <value>`), optionally prefixed with
     * `--set-unlock-pin`. This is the install-time equivalent of the heartbeat strategy push,
     * for baking defaults into an installer/MDM script.
     *
     * @param  array<string, mixed>  $options  config_options map (key => value), empty values skipped
     * @return array<string, string> OS label => newline-joined command block
     */
    public function installScript(array $options, string $unlockPin = ''): array
    {
        if ($unlockPin !== '' && ! self::isValidUnlockPin($unlockPin)) {
            throw new InvalidArgumentException('The unlock PIN contains unsupported characters or has an invalid length.');
        }

        $normalizedOptions = [];
        foreach ($options as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;

            if (! self::isValidOptionKey($key)) {
                throw new InvalidArgumentException('A strategy option key contains unsupported characters.');
            }
            if (self::containsControlCharacters($value)) {
                throw new InvalidArgumentException('A strategy option value contains unsupported control characters.');
            }
            if ($value !== '') {
                $normalizedOptions[$key] = $value;
            }
        }

        $binaries = [
            'Linux' => ['command' => 'sudo rustdesk', 'shell' => 'posix'],
            'macOS' => ['command' => 'sudo /Applications/RustDesk.app/Contents/MacOS/rustdesk', 'shell' => 'posix'],
            // Generate PowerShell rather than cmd.exe syntax. PowerShell single-quoted
            // arguments do not expand %, !, $, subexpressions, or backticks.
            'Windows' => ['command' => '& "$env:ProgramFiles\\RustDesk\\rustdesk.exe"', 'shell' => 'powershell'],
        ];

        $scripts = [];
        foreach ($binaries as $os => $binary) {
            $quote = $binary['shell'] === 'powershell'
                ? self::quotePowerShellArgument(...)
                : self::quotePosixArgument(...);
            $lines = [];
            if ($unlockPin !== '') {
                $lines[] = $binary['command'].' --set-unlock-pin '.$quote($unlockPin);
            }
            foreach ($normalizedOptions as $key => $value) {
                $lines[] = $binary['command'].' --option '.$quote($key).' '.$quote($value);
            }
            $scripts[$os] = implode("\n", $lines);
        }

        return $scripts;
    }

    /**
     * Strategy option names become command-line arguments in deployment output. Keep the
     * accepted syntax aligned with RustDesk's hyphenated option vocabulary while excluding
     * whitespace, shell metacharacters, quotes, and control characters.
     */
    public static function isValidOptionKey(string $key): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,254}\z/D', $key) === 1;
    }

    /**
     * RustDesk requires 4-128 characters. The generator intentionally accepts only an
     * unreserved, portable subset so a PIN stays safe across POSIX shells and PowerShell.
     */
    public static function isValidUnlockPin(string $pin): bool
    {
        // The allow-list below is ASCII-only, so byte length and character length match.
        $length = strlen($pin);

        return $length >= self::UNLOCK_PIN_MIN_LENGTH
            && $length <= self::UNLOCK_PIN_MAX_LENGTH
            && preg_match('/\A[A-Za-z0-9._~-]+\z/D', $pin) === 1;
    }

    /**
     * Full-admin-only option keys whose presence or value differs between two option maps.
     * Keys are compared case-insensitively so a case variant cannot slip past the check.
     *
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @return list<string>
     */
    public static function changedFullAdminOnlyKeys(array $before, array $after): array
    {
        $protected = static function (array $options): array {
            $result = [];
            foreach ($options as $key => $value) {
                $normalized = strtolower(trim((string) $key));
                if (in_array($normalized, self::FULL_ADMIN_ONLY_OPTION_KEYS, true)) {
                    $result[$normalized] = (string) $value;
                }
            }

            return $result;
        };

        $old = $protected($before);
        $new = $protected($after);
        $changed = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                $changed[] = $key;
            }
        }

        sort($changed);

        return $changed;
    }

    public static function containsControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private static function quotePosixArgument(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private static function quotePowerShellArgument(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
