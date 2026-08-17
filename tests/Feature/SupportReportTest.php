<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The report an operator pastes into a public issue.
 *
 * Two things have to hold at once, and they pull against each other: nothing identifying
 * may survive, and enough must survive to be worth reading. The tests below check both
 * ends, because a report that leaks is dangerous and a report that says nothing is just a
 * button nobody presses.
 */
class SupportReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_report_answers_the_questions_a_maintainer_asks_first(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.support'))->assertOk()->getContent();

        foreach ([
            'RD-API-Server support report',
            config('app.version'),      // which version
            PHP_VERSION,                // which PHP
            'Browser remote desktop',   // the diagnostics, inline
            'Recent log',               // and what the log says
            'Database',
        ] as $expected) {
            $this->assertStringContainsString((string) $expected, $html);
        }
    }

    public function test_configured_secrets_are_described_but_never_shown(): void
    {
        // The useful answer is "a key is set and it is the right half", which cannot leak
        // anything by being wrong about what to hide.
        $public = base64_encode(random_bytes(32));
        config(['rustdesk.key' => $public, 'rustdesk.key_file' => '']);

        $html = $this->actingAs($this->admin())->get(route('admin.support'))->assertOk()->getContent();

        $this->assertStringNotContainsString($public, $html);
        $this->assertStringContainsString('public key, valid', $html);
    }

    public function test_a_private_key_is_called_out_rather_than_printed(): void
    {
        $private = base64_encode(random_bytes(32).random_bytes(32));
        config(['rustdesk.key' => $private, 'rustdesk.key_file' => '']);

        $html = $this->actingAs($this->admin())->get(route('admin.support'))->assertOk()->getContent();

        $this->assertStringNotContainsString($private, $html);
        $this->assertStringContainsString('PRIVATE KEY CONFIGURED', $html);
    }

    public function test_log_contents_are_redacted(): void
    {
        // The log is the part most likely to contain an address or a hostname, and the part
        // an operator is least likely to have read before pasting it.
        File::ensureDirectoryExists(storage_path('logs'));
        File::put(storage_path('logs/laravel.log'), implode("\n", [
            '[2026-08-17 02:00:00] production.ERROR: connect failed to id.customer-internal.example',
            'peer 345890346 from 203.0.113.44 rejected',
            'DB_PASSWORD=hunter2 in config',
        ]));

        $body = (string) $this->actingAs($this->admin())
            ->get(route('admin.support.download'))->assertOk()->getContent();

        foreach (['customer-internal.example', '345890346', '203.0.113.44', 'hunter2'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "{$secret} must not reach the report");
        }

        // Still readable as a sequence of events.
        $this->assertStringContainsString('production.ERROR', $body);
        $this->assertStringContainsString('2026-08-17 02:00:00', $body);
        $this->assertStringContainsString('Replaced with placeholders', $body);

        File::delete(storage_path('logs/laravel.log'));
    }

    public function test_the_download_is_an_attachment_and_is_never_cached(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.support.download'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
    }

    public function test_the_report_requires_the_settings_permission(): void
    {
        // It describes the deployment. That is not for every account with a console login.
        $stranger = User::create([
            'username' => 'stranger', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($stranger)->get(route('admin.support'))->assertRedirect(route('admin.login'));
        $this->actingAs($stranger)->get(route('admin.support.download'))->assertRedirect(route('admin.login'));
    }

    public function test_the_version_is_visible_on_every_page(): void
    {
        // The first question on every support thread, answerable from wherever the operator
        // already is rather than from a settings screen they have to find.
        $html = $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('rd-sidebar__version', $html);
        $this->assertStringContainsString('v'.config('app.version'), $html);
    }

    private function admin(): User
    {
        $user = User::create([
            'username' => 'support-admin', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL,
        ]);
        $user->is_admin = true;
        $user->save();

        return $user;
    }
}
