<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OauthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * OAuth / OIDC device-login flow for the RustDesk client
 * (docs/modernization/02-client-api-contract.md §3a, mirroring legacy Go ouath.go).
 *
 * Flow:
 *   1. POST /api/oidc/auth         → {code, url}
 *   2. client opens url; provider redirects to GET /api/oauth/callback?code=&state=, which shows
 *      an approval page (device name / id / requesting IP / time)
 *   3. the account holder approves: POST /api/oidc/confirm (one-time nonce + browser cookie)
 *   4. client polls GET /api/oidc/auth-query?code=&id=&uuid= → {"body": "<AuthBody json>"}
 *      (unchanged: it keeps receiving the pending error until step 3 completes)
 *
 * Never throws to the client: every error path returns {"error": ...} (or HTML on callback).
 */
class OauthController extends Controller
{
    /** Cookie binding the approval POST to the browser that completed the provider sign-in. */
    public const APPROVAL_COOKIE = 'rd_oidc_approval';

    public function __construct(private readonly OauthService $oauth) {}

    /**
     * POST /api/oidc/auth
     * Body: {op, id, uuid, deviceInfo:{os,type,name}}.
     * Starts a pending session and returns {code, url} for the provider authorization screen.
     */
    public function auth(Request $request): JsonResponse
    {
        $op = trim((string) $request->input('op', ''));
        if ($op === '') {
            return response()->json(['error' => 'Missing op']);
        }

        $deviceInfo = $request->input('deviceInfo', []);
        $deviceInfo = is_array($deviceInfo) ? $deviceInfo : [];

        [$code, $url] = $this->oauth->beginAuth(
            $op,
            (string) $request->input('id', ''),
            (string) $request->input('uuid', ''),
            $deviceInfo,
            $request->ip(),
        );

        if ($code === '' || $url === '') {
            return response()->json(['error' => 'OAuth provider not found or misconfigured']);
        }

        return response()->json([
            'code' => $code,
            'url' => $url,
        ]);
    }

    /**
     * GET /api/oauth/callback (alias /api/oidc/callback)
     * Provider redirect target. Exchanges the code and resolves the user, then renders an
     * approval page; no token is issued until the account holder approves (confirm()).
     */
    public function callback(Request $request): Response
    {
        $state = $this->queryString($request, 'state');
        $code = $this->queryString($request, 'code');
        $error = $this->queryString($request, 'error');

        if ($error !== '') {
            return $this->page('Sign-in failed', 'The provider reported: '.e($error), false);
        }

        $result = $this->oauth->handleCallback($state, $code);

        if (! $result['ok']) {
            return $this->page('Sign-in failed', e($result['error']), false);
        }

        $confirmation = $result['confirmation'] ?? null;
        if (! is_array($confirmation)) {
            return $this->page(
                'Sign-in complete',
                'You have signed in successfully. You can now return to the RustDesk app.',
                true,
            );
        }

        $response = response()
            ->view('oidc.confirm', [
                'confirmation' => $confirmation,
                'action' => url('/api/oidc/confirm'),
                'viewerIp' => (string) $request->ip(),
            ])
            ->withHeaders($this->sensitiveHeaders());

        $response->headers->setCookie(new Cookie(
            self::APPROVAL_COOKIE,
            (string) $confirmation['browser_secret'],
            now()->addSeconds(OauthService::CACHE_TTL),
            '/api/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_STRICT,
        ));

        return $response;
    }

    /**
     * POST /api/oidc/confirm
     * The approval form on the callback page. Requires the page's one-time nonce and the
     * browser-binding cookie set with it; "deny" discards the pending sign-in.
     */
    public function confirm(Request $request): Response
    {
        $approve = $request->input('decision') === 'approve';
        $result = $this->oauth->approveSignIn(
            $this->inputString($request, 'state'),
            $this->inputString($request, 'nonce'),
            (string) $request->cookie(self::APPROVAL_COOKIE, ''),
            $approve,
        );

        if (! $result['ok']) {
            $response = $this->page('Sign-in failed', e($result['error']), false);
        } elseif ($result['approved']) {
            $response = $this->page(
                'Sign-in complete',
                'The device is now signed in. You can return to the RustDesk app.',
                true,
            );
        } else {
            $response = $this->page(
                'Sign-in denied',
                'The sign-in request was discarded and no access was granted. You can close this page.',
                false,
            );
        }

        $response->headers->clearCookie(self::APPROVAL_COOKIE, '/api/', null, $request->isSecure(), true, Cookie::SAMESITE_STRICT);

        return $response;
    }

    /**
     * GET /api/oidc/auth-query?code=&id=&uuid=
     *
     * Returns the AuthBody once login completes, or the pending error
     * ("No authed oidc is found") while it hasn't.
     *
     * Dual shape for cross-version client compatibility:
     *   - the AuthBody (or {error}) is returned at the TOP LEVEL — this is what stable
     *     RustDesk clients (and the reference Go server, lejianwen/rustdesk-api) parse:
     *     `HbbHttpResponse::parse(&resp)` → serde `AuthBody` directly;
     *   - the same JSON is ALSO mirrored under `body` — newer (post-2026-04) clients read
     *     `{"body":"<json string>"}` first.
     * serde ignores unknown fields, so each client generation parses the response it expects.
     */
    public function authQuery(Request $request): JsonResponse
    {
        $code = (string) $request->query('code', '');
        $json = $this->oauth->pollResult(
            $code,
            (string) $request->query('id', ''),
            (string) $request->query('uuid', ''),
        );

        // Decode to OBJECTS (not assoc arrays) so an empty `info` stays `{}` and isn't
        // re-encoded as `[]` — the client's serde UserInfo expects an object, and `[]` would
        // fail AuthBody deserialization.
        $payload = json_decode($json);
        if (! $payload instanceof \stdClass) {
            $payload = new \stdClass;
        }
        $payload->body = $json;

        return response()->json($payload);
    }

    /**
     * GET /api/oauth/msg (alias /api/oidc/msg)
     * Minimal message endpoint mirroring the Go server (returns a tiny JS snippet).
     */
    public function msg(Request $request): Response
    {
        $title = (string) $request->query('title', '');
        $msg = (string) $request->query('msg', '');

        $js = '';
        if ($title !== '') {
            $js .= "title='".addslashes($title)."';";
        }
        if ($msg !== '') {
            $js .= "msg='".addslashes($msg)."';";
        }

        return response($js, 200)->header('Content-Type', 'application/javascript');
    }

    /**
     * Render the small status page shown to the user in the browser after callback.
     */
    private function page(string $title, string $message, bool $ok): Response
    {
        $color = $ok ? '#05c27b' : '#ff3366';
        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
  body { margin:0; font-family:Inter,-apple-system,Segoe UI,Roboto,sans-serif;
         background:#070d19; color:#d3d8e3; display:flex; align-items:center;
         justify-content:center; min-height:100vh; }
  .card { background:#0c1427; border:1px solid #1b2942; border-radius:12px;
          padding:32px 40px; max-width:420px; text-align:center; }
  h1 { color:{$color}; font-size:20px; margin:0 0 12px; }
  p { color:#7987a1; font-size:14px; line-height:1.5; margin:0; }
</style>
</head>
<body>
  <div class="card">
    <h1>{$title}</h1>
    <p>{$message}</p>
  </div>
</body>
</html>
HTML;

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->withHeaders($this->sensitiveHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function sensitiveHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'",
        ];
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key, '');

        return is_string($value) ? $value : '';
    }

    private function inputString(Request $request, string $key): string
    {
        $value = $request->input($key, '');

        return is_string($value) ? $value : '';
    }
}
