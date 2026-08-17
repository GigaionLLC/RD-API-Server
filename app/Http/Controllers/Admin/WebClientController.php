<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\AdminScopeService;
use App\Services\ServerKeyService;
use App\Services\WebClientDiagnosticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
    /** Where install-assets.mjs and the image build publish the viewer. */
    private const ASSET_BASE = 'assets/webclient/src/ui';

    public function __construct(
        private readonly AdminScopeService $scope,
        private readonly WebClientDiagnosticsService $diagnostics,
        private readonly ServerKeyService $serverKeys,
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
        $path = public_path(self::ASSET_BASE.'/viewer.html');
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

        // A <base> is not decoration here, it is what makes the document work at all.
        //
        // This route serves the viewer's HTML from an application URL, not from the
        // directory the file lives in, and relative URLs resolve against the document's
        // address. `src="./viewer.js"` therefore asks for `/admin/remote/viewer.js`, which
        // does not exist. The browser reports one 404 and stops; a module that fails to
        // load takes its whole graph with it, so nothing runs — and the viewer renders as
        // bare HTML, offering its manual connection form on a page whose configuration was
        // injected correctly a line above. That is what an operator saw for three releases.
        //
        // Everything inside the module graph then resolves correctly on its own, because
        // an import resolves against the importing module's URL rather than the document's.
        // The trailing slash is load-bearing and `asset()` strips it: without one, the last
        // segment is treated as a filename and every relative URL resolves one directory
        // too high — the same 404, from a <base> that looks right.
        $base = '<base href="'.e(asset(self::ASSET_BASE)).'/">';
        $document = str_contains($document, '<head>')
            ? str_replace('<head>', '<head>'."\n".$base, $document)
            : $base.$document;

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
     * Searched rather than listed. A fleet is thousands of devices, and rendering them into
     * a `<select>` builds every one into the DOM on page load — slowest for exactly the
     * deployments with the most machines, and unsearchable for the operator, who is left
     * scrolling for a hostname they already know.
     *
     * The result is capped and the query is scoped, so this is not a way to enumerate
     * devices outside the operator's boundary either.
     */
    public function searchPeers(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $q = trim((string) $request->query('q', ''));

        $devices = $this->scope
            ->scopeDevices(Device::query(), $actor, 'devices.view')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('rustdesk_id', 'like', "%{$q}%")
                ->orWhere('hostname', 'like', "%{$q}%")
                ->orWhere('alias', 'like', "%{$q}%")))
            // Online and recently seen first: with no search term this is the "who was I
            // just working on" list, which is what an operator opening the page wants.
            ->orderByDesc('is_online')
            ->orderByDesc('last_online_at')
            ->limit(20)
            ->get(['rustdesk_id', 'alias', 'hostname', 'is_online']);

        return response()->json($devices->map(fn (Device $d) => [
            // The peer id, not the primary key: this picker chooses something to connect
            // to, and an id typed by hand has to produce the same value.
            'id' => $d->rustdesk_id,
            'text' => ($d->hostname ?: $d->alias ?: $d->rustdesk_id)
                .' ('.$d->rustdesk_id.')'
                .($d->is_online ? '' : ' · offline'),
        ])->all());
    }

    /**
     * The RustDesk public key. Read server-side so the viewer cannot be pointed at a
     * different server by editing a form, and always the public half — see ServerKeyService
     * for why a deployment configured with the private one still works and is still told.
     */
    private function serverKey(): string
    {
        return $this->serverKeys->publicKey();
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
        return File::isFile(public_path(self::ASSET_BASE.'/viewer.js'));
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
