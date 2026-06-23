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
        $host = trim((string) $request->query('host', ''));
        $relay = trim((string) $request->query('relay', ''));
        // Default the API server to this panel's own public URL.
        $api = trim((string) $request->query('api', $request->getSchemeAndHttpHost()));
        $key = trim((string) $request->query('key', ''));

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
}
