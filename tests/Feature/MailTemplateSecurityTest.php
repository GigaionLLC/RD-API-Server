<?php

namespace Tests\Feature;

use App\Models\MailLog;
use App\Models\MailTemplate;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailTemplateSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_values_are_html_escaped_before_delivery_and_logging(): void
    {
        Mail::fake();
        $template = MailTemplate::create([
            'type' => MailService::TYPE_ALARM,
            'name' => 'Security alarm',
            'subject' => 'Alert',
            'contents' => '<p>{$message}</p><a href="{$link}">open</a>',
        ]);

        $sent = app(MailService::class)->send(
            null,
            $template->id,
            'ops@example.test',
            'mail-uuid',
            [
                'message' => '<img src=x onerror="alert(1)">',
                'link' => 'https://example.test/?a=1&b="onmouseover=alert(1)"',
            ]
        );

        $this->assertTrue($sent);
        $contents = MailLog::firstOrFail()->contents;
        $this->assertStringContainsString(
            '&lt;img src=x onerror=&quot;alert(1)&quot;&gt;',
            $contents
        );
        $this->assertStringContainsString(
            'https://example.test/?a=1&amp;b=&quot;onmouseover=alert(1)&quot;',
            $contents
        );
        $this->assertStringNotContainsString('<img', $contents);
        $this->assertStringNotContainsString('href="https://example.test/?a=1&b=', $contents);
    }
}
