<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser hardening for the admin console and other web routes.
 *
 * - `X-Content-Type-Options: nosniff` stops MIME sniffing of responses.
 * - `Referrer-Policy: strict-origin-when-cross-origin` keeps paths and query strings on-site.
 * - `Content-Security-Policy: frame-ancestors 'self'` (plus the legacy `X-Frame-Options`) lets
 *   the console frame its own pages (the browser viewer) but no other origin frame the console.
 * - `Strict-Transport-Security` is sent only on HTTPS requests, so plain-HTTP and LAN
 *   deployments are never pinned to HTTPS by accident.
 *
 * Headers a response already set (for example the viewer frame's stricter Referrer-Policy) are
 * left untouched.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
        ];
        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $csp = (string) $response->headers->get('Content-Security-Policy', '');
        if (! str_contains($csp, 'frame-ancestors')) {
            $response->headers->set(
                'Content-Security-Policy',
                trim($csp === '' ? "frame-ancestors 'self'" : rtrim($csp, '; ')."; frame-ancestors 'self'"),
            );
        }

        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
