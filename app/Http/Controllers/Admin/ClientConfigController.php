<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Strategy;
use App\Services\AdminScopeService;
use App\Services\ClientConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * "Client Config" generator: produces the RustDesk server-config string, the mobile QR code,
 * and the renamed-installer filename so admins can roll out pre-configured clients without
 * editing each one by hand (the open-source equivalent of Pro's client generator).
 */
class ClientConfigController extends Controller
{
    public function __construct(private readonly AdminScopeService $scope) {}

    public function index(Request $request, ClientConfigService $config): Response
    {
        // Keep non-sensitive GET deep links working, but deliberately ignore unlock_pin even
        // when a legacy/bookmarked URL includes it. Sensitive output is generated only by POST.
        return $this->renderPage($request, $config, $request->only([
            'host', 'relay', 'api', 'key', 'strategy',
        ]));
    }

    public function generate(Request $request, ClientConfigService $config): Response|RedirectResponse
    {
        // Read generation inputs strictly from the POST body. In particular, never accept an
        // unlock PIN supplied in the query string, where it would enter browser/proxy logs.
        $payload = $request->request->all();
        $validator = Validator::make($payload, [
            'host' => ['nullable', 'string', 'max:255'],
            'relay' => ['nullable', 'string', 'max:255'],
            'api' => ['nullable', 'string', 'max:2048'],
            'key' => ['nullable', 'string', 'max:4096'],
            'strategy' => ['nullable', 'integer', 'exists:strategies,id'],
            'unlock_pin' => [
                'nullable',
                'string',
                'min:'.ClientConfigService::UNLOCK_PIN_MIN_LENGTH,
                'max:'.ClientConfigService::UNLOCK_PIN_MAX_LENGTH,
                'regex:/\A[A-Za-z0-9._~-]+\z/D',
            ],
        ], [
            'unlock_pin.regex' => 'The unlock PIN may contain only letters, numbers, periods, underscores, tildes, and hyphens.',
        ]);

        if ($validator->fails()) {
            // Never flash the PIN to the session on a validation redirect.
            $response = redirect()
                ->route('admin.client-config.index')
                ->withErrors($validator)
                ->withInput(Arr::only($payload, ['host', 'relay', 'api', 'key', 'strategy']));

            $this->applyNoStoreHeaders($response);

            return $response;
        }

        return $this->renderPage($request, $config, $validator->validated());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function renderPage(Request $request, ClientConfigService $config, array $input): Response
    {
        // Auto-fill from the server's own configured values (env / mounted key file); the
        // admin can still override per-generation via the form.
        $defaults = $this->serverDefaults($request);
        $host = trim((string) ($input['host'] ?? $defaults['host']));
        $relay = trim((string) ($input['relay'] ?? $defaults['relay']));
        $api = trim((string) ($input['api'] ?? $defaults['api']));
        $key = trim((string) ($input['key'] ?? $defaults['key']));
        $unlockPin = trim((string) ($input['unlock_pin'] ?? ''));

        $configString = $installer = $qrSvg = null;
        if ($host !== '' || $key !== '') {
            $configString = $config->configString($host, $relay, $api, $key);
            $installer = $config->installerFilename($host, $relay, $api, $key);
            $qrSvg = $config->qrSvg($config->qrPayload($host, $relay, $api, $key));
        }

        // Optional: turn a Strategy's options into a paste-ready install script
        // (`rustdesk --option <key> <value>` per option, + the unlock PIN when set).
        $strategies = $this->scope->scopeStrategies(
            Strategy::query(),
            $request->user(),
            'deploy.view',
        )->orderBy('name')->get(['id', 'name']);
        $strategyId = (int) ($input['strategy'] ?? 0);
        $selectedStrategy = null;
        if ($strategyId > 0) {
            $selectedStrategy = Strategy::find($strategyId);
            if ($selectedStrategy !== null) {
                $this->scope->authorizeStrategy($request->user(), $strategyId, 'deploy.view');
            }
        }
        $installScript = null;
        $pinCommands = null;
        $scriptWarning = null;

        try {
            if ($selectedStrategy) {
                $installScript = $config->installScript((array) ($selectedStrategy->options ?? []), $unlockPin);
            }
            if ($unlockPin !== '') {
                // The standalone PIN card consumes the same safely quoted service output.
                $pinCommands = $config->installScript([], $unlockPin);
            }
        } catch (InvalidArgumentException) {
            // Legacy data may predate the key/value validation. Refuse to render executable
            // output and direct the admin to repair the strategy instead of emitting it.
            $installScript = null;
            $pinCommands = null;
            $scriptWarning = 'The selected strategy contains an option that cannot be rendered safely. Edit the strategy and remove unsupported keys or control characters.';
        }

        $response = response()->view('admin.client_config.index', compact(
            'host', 'relay', 'api', 'key', 'configString', 'installer', 'qrSvg', 'unlockPin',
            'strategies', 'strategyId', 'selectedStrategy', 'installScript', 'pinCommands',
            'scriptWarning'
        ));
        $this->applyNoStoreHeaders($response);

        return $response;
    }

    private function applyNoStoreHeaders(Response|RedirectResponse $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
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
