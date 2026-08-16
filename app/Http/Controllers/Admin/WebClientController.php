<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\AdminScopeService;
use App\Services\WebClientDiagnosticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use RuntimeException;
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
    public function __construct(
        private readonly AdminScopeService $scope,
        private readonly WebClientDiagnosticsService $diagnostics,
    ) {}

    /**
     * A read-only account of whether this deployment can actually run the viewer.
     *
     * Every way this feature fails to start looks the same from the browser — a connection
     * error against a server that is running fine — and none of the causes appear in a log.
     * They are all in the deployment: a missing TLS terminator, an untrusted proxy, an
     * upstream on the wrong network. The page names each one.
     */
    public function diagnostics(Request $request): View
    {
        return view('admin.web_client.diagnostics', [
            'report' => $this->diagnostics->report($request),
        ]);
    }

    /**
     * Remote control by peer id.
     *
     * The device list is the usual way in, and is a boundary: an operator sees the peers
     * they are scoped to. But a support desk is regularly given an id over the phone for a
     * machine that has never checked in here, and sending them to a different tool for
     * that is the kind of gap that gets a feature abandoned.
     *
     * So an id outside the device list is accepted — from an operator whose device
     * permission is unrestricted. Anyone scoped to a group keeps that boundary, because
     * otherwise typing an id by hand would be a way around it.
     */
    public function remote(Request $request): View
    {
        /** @var User $actor */
        $actor = $request->user();
        $peer = $this->requestedPeer($request);

        return view('admin.web_client.remote', [
            'peer' => $peer,
            'device' => $peer === '' ? null : $this->deviceFor($peer),
            'unrestricted' => $this->scope->isUnrestricted($actor, 'devices.view'),
            'known' => $this->knownPeers($actor),
            'config' => $peer === '' ? null : $this->clientConfig(null, $actor, $peer),
            'assetsPresent' => $this->assetsPresent(),
            'needsWsUrls' => $this->needsWsUrls($this->clientConfig(null, $actor, $peer ?: 'preview')),
            'diagnostics' => route('admin.web-client.diagnostics'),
        ]);
    }

    /**
     * The viewer document for a peer id typed by hand.
     */
    public function remoteFrame(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $peer = $this->requestedPeer($request);

        if ($peer === '') {
            throw new NotFoundHttpException('no peer id');
        }

        $this->authorizePeer($actor, $peer);

        return $this->viewerDocument($this->clientConfig(null, $actor, $peer));
    }

    /**
     * Connect, from the device list.
     *
     * This hands the peer id to the remote control screen rather than opening a session.
     * Nothing in this feature connects on its own: a remote desktop session is visible on
     * the other machine and interrupts whoever is using it, so it has to be the result of
     * someone deciding to, not of a mis-click on a list of five devices.
     */
    public function show(Request $request, Device $device): RedirectResponse
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

        return redirect()->route('admin.remote', ['peer' => $device->rustdesk_id]);
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

        return $this->viewerDocument($this->clientConfig($device, $actor));
    }

    /**
     * The viewer document with its configuration injected.
     *
     * The injection is verified rather than assumed. `str_replace` finding nothing is
     * silent, and the result is a viewer that falls back to its manual connection form —
     * which reads as a half-finished feature rather than a failure, and asks the operator
     * for an ID server and key they should never have to know. A 500 naming the cause is
     * far kinder.
     *
     * @param  array<string, mixed>  $config
     */
    private function viewerDocument(array $config): Response
    {
        $path = public_path('assets/webclient/ui/viewer.html');
        if (! File::isFile($path)) {
            throw new NotFoundHttpException('viewer assets are not installed');
        }

        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        // Injected before the module script so RD_CONFIG exists when viewer.js evaluates.
        // JSON_HEX_TAG is not needed because the payload is server-owned config, but the
        // closing-tag guard is cheap insurance against a value ever becoming user data.
        $inject = '<script>window.RD_CONFIG='.str_replace('</', '<\/', $json).';</script>';
        $needle = '<script type="module"';
        $document = (string) File::get($path);

        if (! str_contains($document, $needle)) {
            throw new RuntimeException(
                'The viewer at '.$path.' has no module script tag to inject configuration before. '
                .'The published copy is out of step with this application; republish it with '
                .'`node web-client/scripts/install-assets.mjs`.'
            );
        }

        return response(str_replace($needle, $inject."\n".$needle, $document))
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
     * @param  Device|null  $device  Null when the operator typed a peer id that is not in
     *                               the device list, which a support desk regularly does.
     * @return array<string, mixed>
     */
    private function clientConfig(?Device $device, User $actor, string $peerId = ''): array
    {
        $idServer = (string) config('rustdesk.id_server');
        $relayServer = (string) config('rustdesk.relay_server');
        [$rendezvousUrl, $relayUrl] = $this->wsEndpoints();

        return [
            'host' => $this->hostOf($idServer),
            'rendezvousPort' => $this->portOf($idServer, 21116),
            'relayHost' => $this->hostOf($relayServer),
            'relayPort' => $this->portOf($relayServer, 21117),
            'serverKey' => $this->serverKey(),
            // Explicit wss endpoints win over the derived ports. Behind a reverse proxy
            // the ports are usually not exposed at all, and a secure page cannot open ws://.
            'rendezvousUrl' => $rendezvousUrl,
            'relayUrl' => $relayUrl,
            'peerId' => $device?->rustdesk_id ?: $peerId,
            'peerLabel' => $device
                ? ($device->alias ?: ($device->hostname ?: $device->rustdesk_id))
                : $peerId,
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
     * The peer id from the request, reduced to what a RustDesk id can contain.
     *
     * Ids are digits, but an operator reads them off a screen that groups them — "345 890
     * 346" — and will paste them that way, so separators are stripped rather than
     * rejected. Everything else is dropped: this value reaches the viewer's configuration
     * and its rendezvous request, so it is narrowed at the edge instead of trusted.
     */
    private function requestedPeer(Request $request): string
    {
        $raw = (string) $request->query('peer', '');

        return substr(preg_replace('/[^A-Za-z0-9_-]/', '', $raw) ?? '', 0, 64);
    }

    /** @return Device|null The device with this peer id, if this deployment knows it. */
    private function deviceFor(string $peerId): ?Device
    {
        return $peerId === '' ? null : Device::query()->where('rustdesk_id', $peerId)->first();
    }

    /**
     * Whether this operator may open a session to this id.
     *
     * A peer inside their device scope is theirs by the same rule the device list uses.
     * An id from outside it — one read over the phone for a machine that has never checked
     * in here — is allowed only to an operator whose device permission is unrestricted,
     * because otherwise typing an id by hand would be a way around the scope.
     */
    private function authorizePeer(User $actor, string $peerId): void
    {
        if ($this->scope->isUnrestricted($actor, 'devices.view')) {
            return;
        }

        $inScope = $this->scope
            ->scopeDevices(Device::query(), $actor, 'devices.view')
            ->where('rustdesk_id', $peerId)
            ->exists();

        if (! $inScope) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * Peers this operator may reach, for the picker beside the id field.
     *
     * @return Collection<int, Device>
     */
    private function knownPeers(User $actor): Collection
    {
        return $this->scope
            ->scopeDevices(Device::query(), $actor, 'devices.view')
            ->orderByDesc('last_online_at')
            ->limit(200)
            ->get(['id', 'rustdesk_id', 'alias', 'hostname', 'is_online', 'os']);
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

    /**
     * The viewer's two WebSocket endpoints.
     *
     * Explicitly configured URLs win: a deployment that terminates TLS in front of hbbs
     * and hbbr has already said exactly where those endpoints are, and they may not be on
     * this hostname at all.
     *
     * Otherwise, when this runtime is configured to carry the WebSocket itself, the
     * endpoints are this console's own origin. Deriving them rather than asking for them
     * again is the point of that mode: the operator sets two upstreams and nothing else,
     * and the pair cannot drift out of step with the Nginx configuration that serves them.
     *
     * @return array{0: string, 1: string} Rendezvous and relay, both empty when neither is
     *                                     configured.
     */
    private function wsEndpoints(): array
    {
        $id = trim((string) config('rustdesk.web_client.ws_id_url'));
        $relay = trim((string) config('rustdesk.web_client.ws_relay_url'));
        if ($id !== '' && $relay !== '') {
            return [$id, $relay];
        }

        if (! $this->wsProxied()) {
            return [$id, $relay];
        }

        $appUrl = (string) config('app.url');
        $host = (string) (parse_url($appUrl, PHP_URL_HOST) ?: '');
        if ($host === '') {
            return [$id, $relay];
        }

        $port = parse_url($appUrl, PHP_URL_PORT);
        $authority = $host.($port ? ':'.$port : '');
        $scheme = str_starts_with($appUrl, 'https://') ? 'wss' : 'ws';

        return ["{$scheme}://{$authority}/ws/id", "{$scheme}://{$authority}/ws/relay"];
    }

    /**
     * Whether this runtime proxies the viewer's WebSocket to hbbs and hbbr.
     *
     * Both upstreams are required: half a configuration renders one Nginx location and
     * produces a session that connects and then stops, which is harder to diagnose than
     * one that never starts. The runtime configuration script refuses to boot on that,
     * so this only ever disagrees with Nginx on a source deployment that renders its own.
     */
    private function wsProxied(): bool
    {
        return trim((string) config('rustdesk.web_client.ws_id_upstream')) !== ''
            && trim((string) config('rustdesk.web_client.ws_relay_upstream')) !== '';
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

    /**
     * Whether this console will fail to connect for want of the two `wss` endpoints.
     *
     * An HTTPS page cannot open a plain `ws://` socket, and hbbs and hbbr speak plain `ws`
     * only — so a TLS terminator in front of 21118 and 21119 is mandatory here, not a
     * preference. Without it the viewer derives `wss://<id host>:21118`, finds no TLS
     * listening, and reports a connection failure that reads as an unreachable server.
     *
     * Both must be set: one alone is ignored, because half a configuration would produce a
     * rendezvous endpoint with no matching relay.
     *
     * `localhost` is exempt — it is a secure context without TLS, which is what makes a
     * local evaluation work with no proxy at all.
     *
     * @param  array<string, mixed>  $config
     */
    private function needsWsUrls(array $config): bool
    {
        if ($config['secure'] !== true) {
            return false;
        }

        return trim((string) $config['rendezvousUrl']) === ''
            || trim((string) $config['relayUrl']) === '';
    }
}
