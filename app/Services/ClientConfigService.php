<?php

namespace App\Services;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

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
}
