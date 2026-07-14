<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OauthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * OAuth / OIDC device-login flow for the RustDesk client
 * (docs/modernization/02-client-api-contract.md §3a, mirroring legacy Go ouath.go).
 *
 * Flow:
 *   1. POST /api/oidc/auth         → {code, url}
 *   2. client opens url; provider redirects to GET /api/oauth/callback?code=&state=
 *   3. client polls GET /api/oidc/auth-query?code=&id=&uuid= → {"body": "<AuthBody json>"}
 *
 * Never throws to the client: every error path returns {"error": ...} (or HTML on callback).
 */
class OauthController extends Controller
{
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
     * Provider redirect target. Exchanges the code, resolves the user, stores the AuthBody
     * against the pending session, then renders a "return to the app" page.
     */
    public function callback(Request $request): Response
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            return $this->page('Sign-in failed', 'The provider reported: '.e($error), false);
        }

        $result = $this->oauth->handleCallback($state, $code);

        if (! $result['ok']) {
            return $this->page('Sign-in failed', e($result['error']), false);
        }

        return $this->page(
            'Sign-in complete',
            'You have signed in successfully. You can now return to the RustDesk app.',
            true,
        );
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

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
