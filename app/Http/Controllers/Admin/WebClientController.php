<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\AdminScopeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Browser-based remote desktop viewer.
 *
 * Serves the `web-client` bundle with the deployment's own server details injected, so an
 * operator never types an ID server or key. The browser then speaks the RustDesk protocol
 * directly to hbbs/hbbr over WebSocket — this application is not in the media path and
 * runs no proxy, so nothing here is long-lived.
 *
 * Authorization is enforced here rather than in the browser. The viewer is handed
 * connection material for exactly one device, and only after the actor's device scope has
 * been checked; a page that could reach any peer id would make the device list a
 * suggestion rather than a boundary.
 *
 * The connection password is never injected. It is the peer's own secret, this server does
 * not hold it, and the operator supplies it in the viewer.
 */
class WebClientController extends Controller
{
    public function __construct(private readonly AdminScopeService $scope) {}

    /**
     * The viewer page for one device.
     */
    public function show(Request $request, Device $device): View
    {
        /** @var User $actor */
        $actor = $request->user();

        // Re-run the same scope the device list uses. Route-model binding alone would let
        // an operator open any device by guessing its primary key.
        $permitted = $this->scope
            ->scopeDevices(Device::query(), $actor, 'devices.view')
            ->whereKey($device->getKey())
            ->exists();

        if (! $permitted) {
            throw new NotFoundHttpException;
        }

        return view('admin.web_client.show', [
            'device' => $device,
            'config' => $this->clientConfig($device, $actor),
            'assetsPresent' => $this->assetsPresent(),
        ]);
    }

    /**
     * The viewer document, served inside an iframe.
     *
     * This returns the real `viewer.html` from the asset bundle with a config script
     * injected, rather than a Blade copy of its markup. One document means the standalone
     * development page and the embedded one cannot drift apart.
     */
    public function frame(Request $request, Device $device): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $permitted = $this->scope
            ->scopeDevices(Device::query(), $actor, 'devices.view')
            ->whereKey($device->getKey())
            ->exists();

        if (! $permitted) {
            throw new NotFoundHttpException;
        }

        $path = public_path('assets/webclient/ui/viewer.html');
        if (! File::isFile($path)) {
            throw new NotFoundHttpException('viewer assets are not installed');
        }

        $config = $this->clientConfig($device, $actor);
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        // Injected before the module script so RD_CONFIG exists when viewer.js evaluates.
        // JSON_HEX_TAG is not needed because the payload is server-owned config, but the
        // closing-tag guard is cheap insurance against a value ever becoming user data.
        $inject = '<script>window.RD_CONFIG='.str_replace('</', '<\/', $json).';</script>';
        $html = str_replace('<script type="module"', $inject."\n<script type=\"module\"", (string) File::get($path));

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8')
            // Connection material is per-operator and per-device; never let a shared cache
            // hold it.
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Connection material for the viewer.
     *
     * `secure` drives wss. hbbs and hbbr speak plain ws only, so a TLS terminator has to
     * sit in front of them; an https page cannot open a ws:// socket, which makes this a
     * hard requirement rather than a preference in production.
     *
     * @return array<string, mixed>
     */
    private function clientConfig(Device $device, User $actor): array
    {
        $idServer = (string) config('rustdesk.id_server');
        $relayServer = (string) config('rustdesk.relay_server');

        return [
            'host' => $this->hostOf($idServer),
            'rendezvousPort' => $this->portOf($idServer, 21116),
            'relayHost' => $this->hostOf($relayServer),
            'relayPort' => $this->portOf($relayServer, 21117),
            'serverKey' => $this->serverKey(),
            // Explicit wss endpoints win over the derived ports. Behind a reverse proxy
            // the ports are usually not exposed at all, and a secure page cannot open ws://.
            'rendezvousUrl' => trim((string) config('rustdesk.web_client.ws_id_url')),
            'relayUrl' => trim((string) config('rustdesk.web_client.ws_relay_url')),
            'peerId' => $device->rustdesk_id,
            'peerLabel' => $device->alias ?: ($device->hostname ?: $device->rustdesk_id),
            // Shown in the peer's connection manager, so the operator is identifiable there.
            'myName' => (string) ($actor->username ?: 'operator'),
            // Fail closed. This deployment always knows the server key, so a handshake
            // that cannot verify the peer is an attack or a misconfiguration — never a
            // legitimate peer without a registered key. Continuing would put the password
            // proof, keystrokes and screen content on the relay in plaintext.
            'requireEncryption' => $this->serverKey() !== '',
            'secure' => str_starts_with((string) config('app.url'), 'https://'),
        ];
    }

    /**
     * The RustDesk public key, inline or from a file. This is a public value — clients
     * are configured with it — but it is read server-side so the viewer cannot be pointed
     * at a different server by editing a form.
     */
    private function serverKey(): string
    {
        $inline = trim((string) config('rustdesk.key'));
        if ($inline !== '') {
            return $inline;
        }

        $path = trim((string) config('rustdesk.key_file'));

        return $path !== '' && File::isFile($path) ? trim((string) File::get($path)) : '';
    }

    private function hostOf(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        return $endpoint === '' ? '' : (explode(':', $endpoint, 2)[0] ?: '');
    }

    private function portOf(string $endpoint, int $default): int
    {
        $parts = explode(':', trim($endpoint), 2);

        return isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : $default;
    }

    /**
     * The viewer is a set of static ES modules copied out of `web-client/`. When they are
     * missing the page says so rather than rendering a blank canvas, which is otherwise a
     * confusing way to discover the build step was skipped.
     */
    private function assetsPresent(): bool
    {
        return File::isFile(public_path('assets/webclient/ui/viewer.js'));
    }
}
