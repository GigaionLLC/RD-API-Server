<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Explains why the browser remote desktop will or will not work here.
 *
 * Every failure mode this covers presents identically from the viewer — "cannot connect" —
 * while the server it is trying to reach is running perfectly well. The cause is always in
 * the deployment rather than in the code: a missing TLS terminator, an untrusted proxy, a
 * console that believes it is on HTTP while the browser is on HTTPS. None of it is visible
 * from a log, so it is worth stating plainly on a page.
 *
 * The checks that can only be made from the browser — whether the WebSocket endpoint is
 * actually reachable and actually speaks to hbbs — are left to the page's own probe. This
 * class answers what the server can answer on its own.
 */
class WebClientDiagnosticsService
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /**
     * @return array{status: string, transport: string, checks: array<int, array<string, mixed>>,
     *               endpoints: array{rendezvous: string, relay: string}}
     */
    public function report(Request $request): array
    {
        $checks = [
            $this->assetCheck(),
            $this->idServerCheck(),
            $this->serverKeyCheck(),
            $this->secureContextCheck($request),
            $this->proxyTrustCheck($request),
            $this->transportCheck(),
        ];

        foreach ($this->upstreamChecks() as $check) {
            $checks[] = $check;
        }

        return [
            'status' => $this->worst($checks),
            'transport' => $this->transport(),
            'checks' => $checks,
            'endpoints' => $this->endpoints(),
        ];
    }

    /** Which of the three transport arrangements is configured. */
    public function transport(): string
    {
        if ($this->explicitUrls() !== null) {
            return 'explicit';
        }

        return $this->upstreams() !== null ? 'proxied' : 'none';
    }

    /** @return array{rendezvous: string, relay: string} */
    public function endpoints(): array
    {
        $explicit = $this->explicitUrls();
        if ($explicit !== null) {
            return ['rendezvous' => $explicit[0], 'relay' => $explicit[1]];
        }

        if ($this->upstreams() === null) {
            return ['rendezvous' => '', 'relay' => ''];
        }

        $appUrl = (string) config('app.url');
        $host = (string) (parse_url($appUrl, PHP_URL_HOST) ?: '');
        if ($host === '') {
            return ['rendezvous' => '', 'relay' => ''];
        }

        $port = parse_url($appUrl, PHP_URL_PORT);
        $authority = $host.($port ? ':'.$port : '');
        $scheme = str_starts_with($appUrl, 'https://') ? 'wss' : 'ws';

        return [
            'rendezvous' => "{$scheme}://{$authority}/ws/id",
            'relay' => "{$scheme}://{$authority}/ws/relay",
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Individual checks */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function assetCheck(): array
    {
        $present = is_file(public_path('assets/webclient/src/ui/viewer.js'));

        return $this->check(
            'Viewer assets',
            $present ? self::OK : self::FAIL,
            $present
                ? 'Published under public/assets/webclient.'
                : 'Not published. The Docker image installs these during the build; a source deployment runs `node web-client/scripts/install-assets.mjs`.',
        );
    }

    /** @return array<string, mixed> */
    private function idServerCheck(): array
    {
        $id = trim((string) config('rustdesk.id_server'));

        return $this->check(
            'ID server',
            $id === '' ? self::FAIL : self::OK,
            $id === '' ? 'RUSTDESK_ID_SERVER is not set, so the viewer has no rendezvous host.' : $id,
        );
    }

    /** @return array<string, mixed> */
    private function serverKeyCheck(): array
    {
        $key = $this->serverKey();

        // Without a key the viewer cannot verify the peer it is talking to, and a session
        // that cannot be verified is one whose password proof and keystrokes could be read
        // by whatever is in the middle. It still works; it is simply no longer private.
        return $this->check(
            'Server key',
            $key === '' ? self::WARN : self::OK,
            $key === ''
                ? 'Not configured. Sessions cannot be verified end to end, and the viewer will not refuse an unverifiable peer.'
                : 'Configured. The viewer refuses a peer it cannot verify.',
        );
    }

    /** @return array<string, mixed> */
    private function secureContextCheck(Request $request): array
    {
        $appUrl = (string) config('app.url');
        $https = str_starts_with($appUrl, 'https://');
        $host = (string) (parse_url($appUrl, PHP_URL_HOST) ?: '');
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);

        if ($https || $local) {
            return $this->check('Secure context', self::OK,
                $local && ! $https
                    ? 'APP_URL is localhost, which browsers treat as a secure context without TLS.'
                    : 'APP_URL is HTTPS.');
        }

        // Not a preference: WebCodecs is unavailable outside a secure context, so the video
        // pipeline cannot be constructed at all.
        return $this->check('Secure context', self::FAIL,
            'APP_URL is '.($appUrl ?: 'not set').'. Browsers expose WebCodecs only in a secure context, so video cannot be decoded. Serve the console over HTTPS, or use localhost.');
    }

    /** @return array<string, mixed> */
    private function proxyTrustCheck(Request $request): array
    {
        $forwardedProto = (string) $request->headers->get('X-Forwarded-Proto', '');
        $appIsHttps = str_starts_with((string) config('app.url'), 'https://');

        if ($forwardedProto === '') {
            return $this->check('Reverse proxy', self::OK,
                'No forwarded headers on this request; the console appears to be reached directly.');
        }

        // The header arrived. Whether Laravel believed it is the question: an untrusted
        // proxy leaves the application generating http:// asset URLs on an https page,
        // which browsers then block as mixed content — including the viewer's own module.
        if ($request->isSecure()) {
            return $this->check('Reverse proxy', self::OK,
                'Forwarded headers are trusted; this request is seen as HTTPS. Proxy address: '.$request->ip().'.');
        }

        return $this->check('Reverse proxy', $appIsHttps ? self::FAIL : self::WARN,
            'A proxy sent X-Forwarded-Proto: '.$forwardedProto.', but this application does not trust it, so the request is treated as plain HTTP. Add the proxy address ('.$request->ip().') to TRUSTED_PROXIES.');
    }

    /** @return array<string, mixed> */
    private function transportCheck(): array
    {
        return match ($this->transport()) {
            'explicit' => $this->check('Transport', self::OK,
                'Explicit WebSocket endpoints are configured. This server is not in the media path.'),
            'proxied' => $this->check('Transport', self::OK,
                'This container carries the WebSocket and forwards it to hbbs and hbbr. Relayed session video passes through here.'),
            default => $this->check('Transport', self::FAIL,
                'No WebSocket transport configured. Either set RUSTDESK_WS_ID_UPSTREAM and RUSTDESK_WS_RELAY_UPSTREAM to have this container carry it, or set RUSTDESK_WS_ID_URL and RUSTDESK_WS_RELAY_URL to point at your own TLS terminator.'),
        };
    }

    /**
     * Whether the configured upstreams actually answer from inside this container.
     *
     * A name that does not resolve, a service that is not on this network, or a port that
     * is closed all produce the same symptom in the viewer as every other failure here.
     * This is the one check that distinguishes them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function upstreamChecks(): array
    {
        $upstreams = $this->upstreams();
        if ($upstreams === null) {
            return [];
        }

        $out = [];
        foreach (['Rendezvous upstream' => $upstreams[0], 'Relay upstream' => $upstreams[1]] as $label => $target) {
            [$host, $port] = $this->splitHostPort($target);
            $error = '';
            // Short: this runs while an operator waits, and an upstream on the same Compose
            // network answers in single-digit milliseconds or is not there at all.
            $socket = @fsockopen($host, $port, $code, $error, 2.0);

            if ($socket === false) {
                $out[] = $this->check($label, self::FAIL,
                    $target.' did not accept a connection'.($error !== '' ? ': '.$error : '.').' Check the service name, the port, and that both containers share a network.');

                continue;
            }

            fclose($socket);
            $out[] = $this->check($label, self::OK, $target.' accepted a connection.');
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /** @return array{0: string, 1: string}|null */
    private function explicitUrls(): ?array
    {
        $id = trim((string) config('rustdesk.web_client.ws_id_url'));
        $relay = trim((string) config('rustdesk.web_client.ws_relay_url'));

        return $id !== '' && $relay !== '' ? [$id, $relay] : null;
    }

    /** @return array{0: string, 1: string}|null */
    private function upstreams(): ?array
    {
        $id = trim((string) config('rustdesk.web_client.ws_id_upstream'));
        $relay = trim((string) config('rustdesk.web_client.ws_relay_upstream'));

        return $id !== '' && $relay !== '' ? [$id, $relay] : null;
    }

    /** @return array{0: string, 1: int} */
    private function splitHostPort(string $target): array
    {
        $parts = explode(':', $target);
        $port = (int) array_pop($parts);

        return [implode(':', $parts), $port];
    }

    private function serverKey(): string
    {
        $inline = trim((string) config('rustdesk.key'));
        if ($inline !== '') {
            return $inline;
        }

        $path = trim((string) config('rustdesk.key_file'));

        return $path !== '' && is_file($path) ? trim((string) file_get_contents($path)) : '';
    }

    /** @return array<string, mixed> */
    private function check(string $label, string $status, string $detail): array
    {
        return ['label' => $label, 'status' => $status, 'detail' => $detail];
    }

    /** @param  array<int, array<string, mixed>>  $checks */
    private function worst(array $checks): string
    {
        foreach ([self::FAIL, self::WARN] as $level) {
            foreach ($checks as $check) {
                if ($check['status'] === $level) {
                    return $level;
                }
            }
        }

        return self::OK;
    }
}
