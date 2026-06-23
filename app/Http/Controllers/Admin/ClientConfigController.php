<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ClientConfigService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Client Config" generator: produces the RustDesk server-config string, the mobile QR code,
 * and the renamed-installer filename so admins can roll out pre-configured clients without
 * editing each one by hand (the open-source equivalent of Pro's client generator).
 */
class ClientConfigController extends Controller
{
    public function index(Request $request, ClientConfigService $config): View
    {
        // Auto-fill from the server's own configured values (env / mounted key file); the
        // admin can still override per-generation via the form.
        $defaults = $this->serverDefaults($request);
        $host = trim((string) $request->query('host', $defaults['host']));
        $relay = trim((string) $request->query('relay', $defaults['relay']));
        $api = trim((string) $request->query('api', $defaults['api']));
        $key = trim((string) $request->query('key', $defaults['key']));

        $configString = $installer = $qrSvg = null;
        if ($host !== '' || $key !== '') {
            $configString = $config->configString($host, $relay, $api, $key);
            $installer = $config->installerFilename($host, $relay, $api, $key);
            $qrSvg = $config->qrSvg($config->qrPayload($host, $relay, $api, $key));
        }

        return view('admin.client_config.index', compact(
            'host', 'relay', 'api', 'key', 'configString', 'installer', 'qrSvg'
        ));
    }

    /**
     * Best-effort defaults from config/rustdesk.php (env-driven), so the form is pre-filled
     * with this deployment's ID/relay/API servers and public key.
     *
     * @return array{host: string, relay: string, api: string, key: string}
     */
    private function serverDefaults(Request $request): array
    {
        // The config string uses the bare host; the client supplies the standard hbbs ports.
        $strip = static fn (string $h): string => (string) preg_replace('/:(21116|21117)$/', '', trim($h));

        $key = trim((string) config('rustdesk.key', ''));
        $keyFile = trim((string) config('rustdesk.key_file', ''));
        if ($key === '' && $keyFile !== '' && is_file($keyFile)) {
            $key = trim((string) @file_get_contents($keyFile));
        }

        $host = $strip((string) config('rustdesk.id_server', ''));
        $relay = $strip((string) config('rustdesk.relay_server', ''));
        $api = trim((string) config('rustdesk.api_server', ''));

        // Don't pre-fill the obvious loopback placeholders.
        if (str_starts_with($host, '127.0.0.1') || str_starts_with($host, 'localhost')) {
            $host = '';
        }
        if (str_starts_with($relay, '127.0.0.1') || str_starts_with($relay, 'localhost')) {
            $relay = '';
        }
        if ($api === '' || str_starts_with($api, 'http://127.0.0.1') || str_starts_with($api, 'http://localhost')) {
            $api = $request->getSchemeAndHttpHost();
        }

        return ['host' => $host, 'relay' => $relay, 'api' => $api, 'key' => $key];
    }
}
