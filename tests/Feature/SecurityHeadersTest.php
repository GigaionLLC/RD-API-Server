<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Baseline browser hardening on the admin console, plus the deployment-level guards that live in
 * the runtime Nginx template and the standalone web viewer.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_pages_send_baseline_security_headers(): void
    {
        $response = $this->get('/admin/login')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $response->headers->get('Content-Security-Policy'));
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->withServerVariables(['HTTPS' => 'on'])
            ->get('https://localhost/admin/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_runtime_nginx_hides_the_standalone_viewer_and_redacts_oidc_secrets_from_logs(): void
    {
        $template = (string) file_get_contents(base_path('docker/nginx.conf.template'));

        $this->assertMatchesRegularExpression(
            '#location = /assets/webclient/src/ui/viewer\.html \{\s*return 404;#',
            $template,
        );
        $this->assertStringContainsString('oidc/auth-query|oauth/callback|oidc/callback', $template);
        $this->assertStringContainsString('$rd_log_request_uri', $template);
        $this->assertStringNotContainsString('"$request"', $template);
    }

    public function test_standalone_viewer_fails_closed_and_never_takes_server_identity_from_the_url(): void
    {
        $source = (string) file_get_contents(base_path('web-client/src/ui/viewer.js'));

        $this->assertStringContainsString('requireEncryption: true,', $source);
        $this->assertStringContainsString("for (const k of ['peer', 'mode'])", $source);
        $this->assertStringNotContainsString("for (const k of ['host', 'peer', 'key', 'mode'])", $source);
    }
}
