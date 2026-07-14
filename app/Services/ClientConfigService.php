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
     * The Windows renamed-installer filename (the client parses host=,key=,api=,relay= from it).
     */
    public function installerFilename(string $host, string $relay, string $api, string $key): string
    {
        $parts = ['host='.trim($host)];
        if (trim($key) !== '') {
            $parts[] = 'key='.trim($key);
        }
        if (trim($api) !== '') {
            $parts[] = 'api='.trim($api);
        }
        if (trim($relay) !== '') {
            $parts[] = 'relay='.trim($relay);
        }

        return 'rustdesk-'.implode(',', $parts).'.exe';
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
